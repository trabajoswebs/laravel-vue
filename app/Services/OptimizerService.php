<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * OptimizerService
 *
 * Este servicio se encarga de optimizar archivos de imagen asociados a un modelo Media
 * de Spatie Media Library. Puede optimizar tanto el archivo original como sus conversiones
 * (como miniaturas).
 *
 * - Optimiza original + conversions de un Media de Spatie.
 * - En discos locales: in-place.
 * - En S3 (u otros remotos): streaming (readStream/put con resource) + cleanup garantizado.
 * - Devuelve métricas de ahorro y detalle por archivo.
 *
 * Requisitos:
 *  - spatie/image-optimizer + binarios del sistema (jpegoptim, pngquant, cwebp, gifsicle, etc.)
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class OptimizerService
{
    /** Límite defensivo (evita jobs interminables / OOM en cloud). */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB

    /** Whitelist de MIME optimizables. */
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    /**
     * Constructor del servicio.
     *
     * @param  OptimizerChain|null  $optimizer  Cadena de optimización personalizada (inyectada opcionalmente).
     *                                          Si no se inyecta, se crea una con OptimizerChainFactory.
     */
    public function __construct(
        private ?OptimizerChain $optimizer = null
    ) {
        // Si no inyectan chain, crear una con el Factory (trae optimizadores por defecto).
        $this->optimizer ??= OptimizerChainFactory::create();
    }

    /**
     * Optimiza el archivo original y las conversiones especificadas de un Media.
     *
     * Este método determina si el disco es local o remoto y aplica la estrategia
     * de optimización adecuada (in-place para local, streaming para remoto).
     * Devuelve estadísticas sobre el ahorro de bytes y el estado de cada archivo procesado.
     *
     * @param  Media                 $media        Instancia del modelo Media de Spatie.
     * @param  array<int, string>    $conversions  Nombres de las conversiones a optimizar (por defecto: ['thumb','medium','large']).
     * @return array{
     *   files_optimized:int,      // Número de archivos que realmente se redujeron en tamaño
     *   bytes_before:int,         // Total de bytes antes de la optimización
     *   bytes_after:int,          // Total de bytes después de la optimización
     *   bytes_saved:int,          // Total de bytes ahorrados
     *   details: array<int, array{ // Detalles por archivo procesado
     *     path:string,            // Ruta del archivo (relativa o absoluta según disco)
     *     bytes_before:int,       // Tamaño antes de la optimización
     *     bytes_after:int,        // Tamaño después de la optimización
     *     optimized:bool,         // Indica si el archivo se optimizó (tamaño reducido)
     *     error?:string           // Mensaje de error si ocurrió alguno (opcional)
     *   }>
     * }
     */
    public function optimize(Media $media, array $conversions = ['thumb', 'medium', 'large']): array
    {
        // Obtiene el nombre del disco asociado al Media
        $diskName = $media->disk;
        // Obtiene la instancia del disco
        $disk = Storage::disk($diskName);
        // Obtiene el driver del disco (p. ej. 'local', 's3')
        $driver = (string) config("filesystems.disks.{$diskName}.driver");
        // Determina si es un disco local
        $isLocal = in_array($driver, ['local', 'public'], true);

        // Construye la lista de archivos objetivo (original + conversiones)
        $targets = $this->buildTargets($media, $conversions);

        $filesOptimized = 0; // Contador de archivos que se optimizaron (redujeron tamaño)
        $bytesBefore = 0;    // Total de bytes antes de la optimización
        $bytesAfter = 0;     // Total de bytes después de la optimización
        $details = [];       // Detalles por archivo procesado

        // Itera sobre cada archivo objetivo (original o conversión)
        foreach ($targets as $t) {
            $label = $t['type'];      // Etiqueta del archivo (original, conversion:thumb, etc.)
            $full  = $t['full_path']; // Ruta absoluta (local) o null (remoto)
            $rel   = $t['rel_path'];  // Ruta relativa al disco

            try {
                // Aplica la estrategia de optimización según si el disco es local o remoto
                if ($isLocal) {
                    // Optimización local: el archivo se modifica in-place
                    $result = $this->optimizeLocal($full);
                } else {
                    // Optimización remota: se descarga a un temporal, se optimiza localmente y se sube de nuevo
                    $result = $this->optimizeRemote($diskName, $rel);
                }

                // Acumula estadísticas solo con números válidos
                $bytesBefore += max(0, $result['bytes_before']);
                $bytesAfter  += max(0, $result['bytes_after']);

                // Cuenta como optimizado si realmente redujo tamaño
                if ($result['bytes_after'] > 0 && $result['bytes_after'] < $result['bytes_before']) {
                    $filesOptimized++;
                }

                // Añade la ruta al resultado
                $result['path'] = $isLocal ? (string) $full : (string) $rel;
                $details[] = $result;
            } catch (Throwable $e) {
                // En caso de error, registra un log y añade un registro de error a los detalles
                $msg = (string) Str::of($e->getMessage())->limit(160);
                Log::warning('optimizer_service_failed', [
                    'media_id' => $media->id,
                    'disk'     => $diskName,
                    'target'   => $label,
                    'error'    => $msg,
                ]);

                $details[] = [
                    'path'          => $isLocal ? (string) $full : (string) $rel,
                    'bytes_before'  => 0,
                    'bytes_after'   => 0,
                    'optimized'     => false,
                    'error'         => $msg,
                ];
            }
        }

        // Devuelve las estadísticas acumuladas
        return [
            'files_optimized' => $filesOptimized,
            'bytes_before'    => $bytesBefore,
            'bytes_after'     => $bytesAfter,
            'bytes_saved'     => max(0, $bytesBefore - $bytesAfter), // Bytes ahorrados (siempre >= 0)
            'details'         => $details,
        ];
    }

    /**
     * Optimiza un archivo local in-place.
     *
     * Este método se encarga de optimizar un archivo directamente en su ubicación
     * en el sistema de archivos local. Valida el archivo antes de optimizarlo.
     *
     * @param  string|null  $fullPath  Ruta absoluta al archivo local.
     * @return array{bytes_before:int, bytes_after:int, optimized:bool, error?:string}
     */
    private function optimizeLocal(?string $fullPath): array
    {
        // Validaciones básicas del archivo local
        if (!$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'file_not_readable'];
        }

        // MIME defensivo local
        $mime = $this->mimeFromPath($fullPath);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'mime_not_allowed'];
        }

        // Validación de tamaño
        $before = filesize($fullPath) ?: 0;
        if ($before <= 0) {
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'empty_file'];
        }
        if ($before > self::MAX_FILE_SIZE) {
            return ['bytes_before' => $before, 'bytes_after' => $before, 'optimized' => false, 'error' => 'file_too_large'];
        }

        // Aplica la optimización in-place
        $this->optimizer->optimize($fullPath);
        // Limpia la cache de stat para asegurar que filesize() devuelva el tamaño actualizado
        clearstatcache(true, $fullPath);
        $after = filesize($fullPath) ?: $before;

        // Devuelve las estadísticas
        return [
            'bytes_before' => $before,
            'bytes_after'  => $after,
            'optimized'    => $after < $before, // Indica si se redujo el tamaño
        ];
    }

    /**
     * Optimiza un archivo remoto (p.ej. S3) haciendo streaming a un tmp local.
     *
     * Este método descarga el archivo remoto a un archivo temporal local,
     * lo optimiza usando la cadena de optimización local, y luego sube
     * el archivo optimizado de vuelta al disco remoto.
     *
     * @param  string       $diskName      Nombre del disco remoto.
     * @param  string|null  $relativePath  Ruta relativa al archivo en el disco remoto.
     * @return array{bytes_before:int, bytes_after:int, optimized:bool, error?:string}
     */
    private function optimizeRemote(string $diskName, ?string $relativePath): array
    {
        // Obtiene la instancia del disco remoto
        $disk = Storage::disk($diskName);

        // Verifica que el archivo exista en el disco remoto
        if (!$relativePath || !$disk->exists($relativePath)) {
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'remote_not_found'];
        }

        // MIME defensivo
        $mime = (string) ($disk->mimeType($relativePath) ?? '');
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'mime_not_allowed'];
        }

        // Tamaño por API (no cargar a memoria)
        $before = (int) $disk->size($relativePath);
        if ($before <= 0) {
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'empty_remote_file'];
        }
        if ($before > self::MAX_FILE_SIZE) {
            return ['bytes_before' => $before, 'bytes_after' => $before, 'optimized' => false, 'error' => 'file_too_large'];
        }

        // Streaming download → tmp
        $tmp = $this->tempFile('opt_'); // Crea un archivo temporal seguro
        $in  = $disk->readStream($relativePath); // Obtiene un stream de lectura del archivo remoto
        if ($in === false) {
            @unlink($tmp); // Limpia el temporal si falla la lectura
            return ['bytes_before' => 0, 'bytes_after' => 0, 'optimized' => false, 'error' => 'stream_read_failed'];
        }

        try {
            // Copia el stream remoto al archivo temporal local
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                throw new RuntimeException('tmp_open_failed');
            }
            stream_copy_to_stream($in, $out); // Copia el contenido
            fclose($out);
            fclose($in);

            // Optimiza localmente el archivo temporal
            $this->optimizer->optimize($tmp);
            $after = filesize($tmp) ?: $before;

            // Subida por streaming (sobrescribe el archivo original en el disco remoto)
            $fp = fopen($tmp, 'rb'); // Abre el archivo temporal para lectura
            if ($fp === false) {
                throw new RuntimeException('tmp_reopen_failed');
            }

            // Opcional backup sencillo (descomentar si quieres rollback estricto):
            // $backup = $relativePath.'.bak';
            // $disk->copy($relativePath, $backup);

            try {
                // put() con resource hace streaming a S3 en Flysystem v3
                $ok = $disk->put($relativePath, $fp);
                if ($ok === false) {
                    throw new RuntimeException('remote_put_failed');
                }
                // if (isset($backup)) $disk->delete($backup);
            } catch (Throwable $e) {
                // if (isset($backup)) { $disk->move($backup, $relativePath); }
                throw $e;
            } finally {
                fclose($fp); // Cierra el stream del archivo temporal
            }

            // Devuelve las estadísticas
            return [
                'bytes_before' => $before,
                'bytes_after'  => $after,
                'optimized'    => $after < $before, // Indica si se redujo el tamaño
            ];
        } finally {
            // Limpieza garantizada en finally
            if (is_file($tmp)) {
                @unlink($tmp); // Borra el archivo temporal
            }
            if (is_resource($in)) {
                @fclose($in); // Cierra el stream de entrada (aunque ya debería estar cerrado)
            }
        }
    }

    /**
     * Construye lista de objetivos (original + conversions) con rutas absoluta/relativa.
     *
     * Este método genera una lista de archivos (original y conversiones) que
     * deben ser optimizados, incluyendo sus rutas absolutas y relativas.
     *
     * @param  Media              $media        Instancia del modelo Media.
     * @param  array<int, string> $conversions  Nombres de las conversiones.
     * @return array<int, array{type:string, full_path:?string, rel_path:?string}>
     */
    private function buildTargets(Media $media, array $conversions): array
    {
        $targets = [];

        // Original
        $originalFull = $this->safeGetPath($media); // Ruta absoluta del original (si está disponible)
        $originalRel  = $this->safeGetPathRelative($media); // Ruta relativa del original
        $targets[] = [
            'type'      => 'original',
            'full_path' => $originalFull,
            'rel_path'  => $originalRel,
        ];

        // Conversions
        foreach ($conversions as $name) {
            // Defensa básica contra traversal en nombres de conversion
            $safeName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $name) ?: $name;
            [$full, $rel] = $this->safeGetConversionPaths($media, $safeName); // Rutas de la conversión

            $targets[] = [
                'type'      => "conversion:{$safeName}",
                'full_path' => $full,
                'rel_path'  => $rel,
            ];
        }

        return $targets;
    }

    /**
     * Obtiene la ruta absoluta del archivo original si la expone ML.
     *
     * @param  Media  $media  Instancia del modelo Media.
     * @return string|null    Ruta absoluta o null si no está disponible o falla.
     */
    private function safeGetPath(Media $media): ?string
    {
        try {
            if (method_exists($media, 'getPath')) {
                return $media->getPath();
            }
        } catch (Throwable $e) {
            Log::debug('optimizer_service_safe_get_path_failed', [
                'media_id' => $media->id,
                'error' => (string) Str::of($e->getMessage())->limit(120),
            ]);
        }
        return null;
    }

    /**
     * Obtiene la ruta relativa al root del disco (original).
     *
     * @param  Media  $media  Instancia del modelo Media.
     * @return string|null    Ruta relativa o null si no está disponible o falla.
     */
    private function safeGetPathRelative(Media $media): ?string
    {
        try {
            if (method_exists($media, 'getPathRelativeToRoot')) {
                return $media->getPathRelativeToRoot();
            }
            // Fallback defensivo: estructura típica
            $dir  = Arr::get($media->toArray(), 'directory');
            $name = Arr::get($media->toArray(), 'file_name');
            return $dir && $name ? trim((string) $dir, '/').'/'.$name : null;
        } catch (Throwable $e) {
            Log::debug('optimizer_service_safe_get_rel_failed', [
                'media_id' => $media->id,
                'error' => (string) Str::of($e->getMessage())->limit(120),
            ]);
            return null;
        }
    }

    /**
     * Obtiene las rutas (absoluta y relativa) de una conversión.
     *
     * @param  Media   $media      Instancia del modelo Media.
     * @param  string  $conversion Nombre de la conversión.
     * @return array{0:?string, 1:?string}  Array con [ruta_absoluta, ruta_relativa].
     */
    private function safeGetConversionPaths(Media $media, string $conversion): array
    {
        $full = null;
        $rel  = null;

        try {
            if (method_exists($media, 'getPath')) {
                $full = $media->getPath($conversion);
            }
        } catch (Throwable $e) {
            Log::debug('optimizer_service_safe_get_conv_full_failed', [
                'media_id' => $media->id,
                'conv'     => $conversion,
                'error'    => (string) Str::of($e->getMessage())->limit(120),
            ]);
        }

        try {
            if (method_exists($media, 'getPathRelativeToRoot')) {
                $rel = $media->getPathRelativeToRoot($conversion);
            } else {
                // Fallback aproximado (puede no coincidir si personalizaste el patrón)
                $baseRel = $this->safeGetPathRelative($media);
                if ($baseRel) {
                    $filename = pathinfo($baseRel, PATHINFO_FILENAME);
                    $ext      = pathinfo($baseRel, PATHINFO_EXTENSION);
                    $rel = 'conversions/'.$filename.'-'.$conversion.'.'.$ext;
                }
            }
        } catch (Throwable $e) {
            Log::debug('optimizer_service_safe_get_conv_rel_failed', [
                'media_id' => $media->id,
                'conv'     => $conversion,
                'error'    => (string) Str::of($e->getMessage())->limit(120),
            ]);
        }

        return [$full, $rel];
    }

    /**
     * Crea un archivo temporal seguro con tempnam().
     *
     * @param  string  $prefix  Prefijo para el nombre del archivo temporal.
     * @return string          Ruta al archivo temporal.
     */
    private function tempFile(string $prefix = 'opt_'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new RuntimeException('tempnam_failed');
        }
        return $tmp;
    }

    /**
     * Obtiene el MIME real de un archivo local por magic bytes.
     *
     * @param  string  $fullPath  Ruta absoluta al archivo local.
     * @return string             Tipo MIME del archivo.
     */
    private function mimeFromPath(string $fullPath): string
    {
        try {
            $fi = new \finfo(FILEINFO_MIME_TYPE);
            return (string) $fi->file($fullPath);
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }
}