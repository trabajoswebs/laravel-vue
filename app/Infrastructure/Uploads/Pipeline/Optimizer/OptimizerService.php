<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Optimizer;

use App\Infrastructure\Uploads\Pipeline\Optimizer\Adapters\LocalOptimizationAdapter;
use App\Infrastructure\Uploads\Pipeline\Optimizer\Adapters\RemoteDownloader;
use App\Infrastructure\Uploads\Pipeline\Optimizer\Adapters\RemoteUploader;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use Illuminate\Filesystem\FilesystemAdapter;
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
 * - En discos locales (`local`, `public`, o cualquier driver considerado local): optimización "in-place".
 *   Asumimos que estos drivers exponen rutas absolutas legibles y escribibles por PHP.
 * - En S3 (u otros drivers no locales): el archivo se descarga mediante streaming, se optimiza usando
 *   los mismos validadores que el flujo local y luego se vuelve a subir preservando metadatos básicos.
 * - Algunas rutas de conversiones pueden ser heurísticas cuando Media Library no expone `getPathRelativeToRoot()`;
 *   si el patrón no coincide, esa conversión no se optimizará pero el servicio continúa con el resto.
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
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private LocalOptimizationAdapter $localAdapter;

    /**
     * Constructor del servicio.
     *
     * @param  OptimizerChain|null  $optimizer  Cadena de optimización personalizada (inyectada opcionalmente).
     *                                          Si no se inyecta, se crea una con OptimizerChainFactory.
     */
    public function __construct(
        private ?OptimizerChain $optimizer = null,
        ?LocalOptimizationAdapter $localAdapter = null,
    ) {
        // Si no inyectan chain, crear una con el Factory (trae optimizadores por defecto).
        $this->optimizer ??= OptimizerChainFactory::create();
        $this->localAdapter = $localAdapter ?? new LocalOptimizationAdapter(
            $this->optimizer,
            self::MAX_FILE_SIZE,
            self::ALLOWED_MIMES,
        );
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
     *     error?:string,          // Mensaje de error si ocurrió alguno (opcional)
     *     reason_code?:string     // Código de error legible por máquina (opcional)
     *   }>
     * }
     */

    public function optimize(Media $media, array $conversions = ['thumb', 'medium', 'large']): array
    {
        $targets = $this->buildTargets($media, $conversions);

        $metrics = [
            'files_optimized' => 0,
            'bytes_before'    => 0,
            'bytes_after'     => 0,
            'details'         => [],
        ];

        $diskCache = [];
        $driverCache = [];
        $downloaderCache = [];
        $uploaderCache = [];

        foreach ($targets as $target) {
            $outcome = $this->optimizeTarget($media, $target, $diskCache, $driverCache, $downloaderCache, $uploaderCache);

            $metrics['bytes_before'] += \max(0, $outcome['bytes_before'] ?? 0);
            $metrics['bytes_after']  += \max(0, $outcome['bytes_after'] ?? 0);

            if (($outcome['bytes_after'] ?? 0) > 0 && ($outcome['bytes_after'] ?? 0) < ($outcome['bytes_before'] ?? 0)) {
                $metrics['files_optimized']++;
            }

            $metrics['details'][] = $outcome;
        }

        $metrics['bytes_saved'] = \max(0, $metrics['bytes_before'] - $metrics['bytes_after']);

        return $metrics;
    }

    /**
     * Procesa un objetivo individual (original o conversión).
     *
     * @param  array<string,mixed>                    $target
     * @param  array<string,FilesystemAdapter>        $diskCache
     * @param  array<string,string>                   $driverCache
     * @param  array<string,RemoteDownloader>         $downloaderCache
     * @param  array<string,RemoteUploader>           $uploaderCache
     * @return array{bytes_before:int,bytes_after:int,optimized:bool,path:string,error?:string,reason_code?:string}
     */
    private function optimizeTarget(
        Media $media,
        array $target,
        array &$diskCache,
        array &$driverCache,
        array &$downloaderCache,
        array &$uploaderCache,
    ): array
    {
        $label = (string) ($target['type'] ?? 'unknown');
        $diskName = (string) ($target['disk'] ?? '');
        $fullPath = \is_string($target['full_path'] ?? null) ? $target['full_path'] : null;
        $relativePath = \is_string($target['rel_path'] ?? null) ? $target['rel_path'] : null;
        $isLocal = false;

        try {
            $disk = $this->resolveFilesystemAdapter($diskName, $diskCache);
            $driver = $driverCache[$diskName] ??= $this->diskDriver($diskName);
            $isLocal = $this->isLocalDriver($driver);

            $result = $isLocal && $fullPath !== null
                ? $this->localAdapter->optimize($fullPath, $this->detectLocalMime($fullPath))
                : $this->optimizeRemote($diskName, $disk, $relativePath, $downloaderCache, $uploaderCache);

            $result['path'] = $this->resolveResultPath($isLocal, $fullPath, $relativePath);

            return $result;
        } catch (Throwable $e) {
            $msg = (string) Str::of($e->getMessage())->limit(160);
            $reason = $this->resolveReasonCode($e);

            $this->logWarning('media.pipeline.failed', [
                'reason'   => 'optimizer_service_failed',
                'media_id' => $media->id,
                'disk'     => $diskName,
                'target'   => $label,
                'error'    => $msg,
            ]);

            $payload = [
                'path'          => $this->resolveResultPath($isLocal, $fullPath, $relativePath),
                'bytes_before'  => 0,
                'bytes_after'   => 0,
                'optimized'     => false,
                'error'         => $msg,
            ];

            if ($reason !== null) {
                $payload['reason_code'] = $reason;
            }

            return $payload;
        }
    }

    /**
     * Resuelve y cachea el adapter de filesystem para un disco.
     *
     * @param  array<string,FilesystemAdapter> $cache
     */
    private function resolveFilesystemAdapter(string $diskName, array &$cache): FilesystemAdapter
    {
        if ($diskName === '') {
            throw new RuntimeException('disk_name_missing');
        }

        if (!isset($cache[$diskName])) {
            $filesystem = Storage::disk($diskName);
            if (!$filesystem instanceof FilesystemAdapter) {
                throw new RuntimeException("unexpected_filesystem_adapter: {$diskName}");
            }
            $cache[$diskName] = $filesystem;
        }

        return $cache[$diskName];
    }

    /**
     * Determina si un driver de filesystem es local.
     * Determina si un driver de filesystem es local.
     *
     * Consideramos “local” cualquier driver que exponga rutas accesibles directamente
     * desde el filesystem del proceso PHP (por defecto `local` y `public`).
     */
    private function isLocalDriver(?string $driver): bool
    {
        return \in_array($driver, ['local', 'public'], true);
    }

    /**
     * Optimiza un archivo remoto (p.ej. S3) haciendo streaming a un tmp local.
     *
     * Este método descarga el archivo remoto a un archivo temporal local,
     * lo optimiza usando la cadena de optimización local, y luego sube
     * el archivo optimizado de vuelta al disco remoto.
     *
     * @param  string               $diskName      Nombre del disco remoto.
     * @param  FilesystemAdapter    $disk          Instancia del disco remoto.
     * @param  string|null          $relativePath  Ruta relativa al archivo en el disco remoto.
     * @param  array<string,RemoteDownloader> $downloaderCache
     * @param  array<string,RemoteUploader>   $uploaderCache
     * @return array{bytes_before:int, bytes_after:int, optimized:bool, error?:string}
     */
    private function optimizeRemote(
        string $diskName,
        FilesystemAdapter $disk,
        ?string $relativePath,
        array &$downloaderCache,
        array &$uploaderCache,
    ): array
    {
        // Verifica que el archivo exista en el disco remoto
        if (!is_string($relativePath) || $relativePath === '' || !$disk->exists($relativePath)) {
            throw new RuntimeException('remote_not_found');
        }

        $mime = (string) ($disk->mimeType($relativePath) ?? '');
        if (!\in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('mime_not_allowed');
        }

        $before = (int) $disk->size($relativePath);
        if ($before <= 0) {
            throw new RuntimeException('empty_remote_file');
        }
        if ($before > self::MAX_FILE_SIZE) {
            throw new RuntimeException('file_too_large');
        }

        $context = $this->resolveRemoteContext($diskName, $disk, $relativePath, $mime);
        $tmp = $this->tempFile('opt_');
        $downloader = $this->remoteDownloader($diskName, $disk, $downloaderCache);
        $uploader = $this->remoteUploader($diskName, $disk, $uploaderCache);

        try {
            $copied = $downloader->download($relativePath, $tmp, $before);
            $before = $copied;

            $result = $this->localAdapter->optimize($tmp, $mime);

            $uploader->upload($relativePath, $tmp, $context['options']);

            return [
                'bytes_before' => $before,
                'bytes_after'  => $result['bytes_after'],
                'optimized'    => $result['optimized'],
            ];
        } finally {
            if ($context['visibility'] !== null && method_exists($disk, 'setVisibility')) {
                try {
                    $disk->setVisibility($relativePath, $context['visibility']);
                } catch (Throwable $e) {
                    $this->logDebug('optimizer_service_restore_visibility_failed', [
                        'disk'      => $diskName,
                        'path'      => $relativePath,
                        'error'     => (string) Str::of($e->getMessage())->limit(120),
                    ]);
                }
            }

            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Construye lista de objetivos (original + conversions) con rutas absoluta/relativa.
     *
     * Este método genera una lista de archivos (original y conversiones) que
     * deben ser optimizados, incluyendo sus rutas absolutas y relativas.
     * Si Media Library no expone rutas relativas, se usan heurísticas; en dichos casos,
     * una conversión con patrón inesperado puede omitirse silenciosamente.
     *
     * @param  Media              $media        Instancia del modelo Media.
     * @param  array<int, string> $conversions  Nombres de las conversiones.
     * @return array<int, array{type:string, disk:string, full_path:?string, rel_path:?string}>
     */
    private function buildTargets(Media $media, array $conversions): array
    {
        $targets = [];
        $originalDisk = (string) $media->disk;

        // Original
        $originalFull = $this->safeGetPath($media); // Ruta absoluta del original (si está disponible)
        $originalRel  = $this->safeGetPathRelative($media); // Ruta relativa del original
        $targets[] = [
            'type'      => 'original',
            'disk'      => $originalDisk,
            'full_path' => $originalFull,
            'rel_path'  => $originalRel,
        ];

        // Conversions
        foreach ($conversions as $name) {
            // Defensa básica contra traversal en nombres de conversion
            $safeName = preg_replace('/[^a-z0-9_\-]/i', '', (string) $name) ?: $name;
            [$full, $rel] = $this->safeGetConversionPaths($media, $safeName); // Rutas de la conversión
            $conversionDisk = (string) ($media->conversions_disk ?: $originalDisk);

            $targets[] = [
                'type'      => "conversion:{$safeName}",
                'disk'      => $conversionDisk,
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
            $this->logDebug('optimizer_service_safe_get_path_failed', [
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
            // Fallback defensivo: intenta reconstruir `directory/file_name`.
            // Si el patrón no coincide, algunas conversiones podrían no ubicarse.
            $dir  = Arr::get($media->toArray(), 'directory');
            $name = Arr::get($media->toArray(), 'file_name');
            return $dir && $name ? trim((string) $dir, '/') . '/' . $name : null;
        } catch (Throwable $e) {
            $this->logDebug('optimizer_service_safe_get_rel_failed', [
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
            $this->logDebug('optimizer_service_safe_get_conv_full_failed', [
                'media_id' => $media->id,
                'conv'     => $conversion,
                'error'    => (string) Str::of($e->getMessage())->limit(120),
            ]);
        }

        try {
            if (method_exists($media, 'getPathRelativeToRoot')) {
                $rel = $media->getPathRelativeToRoot($conversion);
            } else {
                // Fallback aproximado (si el patrón no coincide, la conversión quedará sin optimizar)
                $baseRel = $this->safeGetPathRelative($media);
                if ($baseRel) {
                    $filename = pathinfo($baseRel, PATHINFO_FILENAME);
                    $ext      = pathinfo($baseRel, PATHINFO_EXTENSION);
                    $rel = 'conversions/' . $filename . '-' . $conversion . '.' . $ext;
                }
            }
        } catch (Throwable $e) {
            $this->logDebug('optimizer_service_safe_get_conv_rel_failed', [
                'media_id' => $media->id,
                'conv'     => $conversion,
                'error'    => (string) Str::of($e->getMessage())->limit(120),
            ]);
        }

        return [$full, $rel];
    }

    /**
     * Determina driver del disco configurado.
     */
    private function diskDriver(string $diskName): string
    {
        return (string) config("filesystems.disks.{$diskName}.driver", '');
    }

    /**
     * Devuelve la ruta más representativa para reporting.
     */
    private function resolveResultPath(bool $isLocal, ?string $fullPath, ?string $relativePath): string
    {
        if ($isLocal && is_string($fullPath) && $fullPath !== '') {
            return $fullPath;
        }

        if (is_string($relativePath) && $relativePath !== '') {
            return $relativePath;
        }

        if (is_string($fullPath) && $fullPath !== '') {
            return $fullPath;
        }

        return '';
    }

    /**
     * Resuelve opciones para sobrescribir objetos remotos preservando metadatos básicos.
     *
     * @return array{options:array<string,mixed>, visibility:?string}
     */
    private function resolveRemoteContext(string $diskName, FilesystemAdapter $disk, string $path, string $mime): array
    {
        $options = [];
        $visibility = null;

        if ($mime !== '') {
            $options['mimetype'] = $mime;
        }

        if (method_exists($disk, 'getVisibility')) {
            try {
                $visibilityValue = $disk->getVisibility($path);
                if (is_string($visibilityValue) && $visibilityValue !== '') {
                    $visibility = $visibilityValue;
                    $options['visibility'] = $visibilityValue;
                }
            } catch (Throwable $e) {
                $this->logDebug('optimizer_service_visibility_lookup_failed', [
                    'disk' => $diskName,
                    'path' => $path,
                    'error' => (string) Str::of($e->getMessage())->limit(120),
                ]);
            }
        }

        $extraOptions = config("media.optimizer.put_options.{$diskName}", []);
        if (is_array($extraOptions) && $extraOptions !== []) {
            $options = array_merge($options, $extraOptions);
        }

        return [
            'options'    => $options,
            'visibility' => $visibility,
        ];
    }

    /**
     * Obtiene (y cachea) el downloader remotos por disco.
     *
     * @param  array<string,RemoteDownloader> $cache
     */
    private function remoteDownloader(string $diskName, FilesystemAdapter $disk, array &$cache): RemoteDownloader
    {
        return $cache[$diskName] ??= new RemoteDownloader($disk);
    }

    /**
     * Obtiene (y cachea) el uploader remoto por disco.
     *
     * @param  array<string,RemoteUploader> $cache
     */
    private function remoteUploader(string $diskName, FilesystemAdapter $disk, array &$cache): RemoteUploader
    {
        return $cache[$diskName] ??= new RemoteUploader($disk);
    }

    /**
     * Detecta el mime local de forma ligera para alinearse con el flujo remoto.
     */
    private function detectLocalMime(string $fullPath): ?string
    {
        if ($fullPath === '' || !is_file($fullPath)) {
            return null;
        }

        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fullPath);
            return \is_string($mime) ? $mime : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convierte mensajes de error en códigos legibles por máquina.
     */
    private function resolveReasonCode(Throwable $e): ?string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return null;
        }

        $normalized = strtolower(Str::slug($message, '_'));
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logDebug(string $message, array $context): void
    {
        Log::debug($message, $this->safeContext($context));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logWarning(string $message, array $context): void
    {
        Log::warning($message, $this->safeContext($context));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function safeContext(array $context): array
    {
        return app(MediaLogSanitizer::class)->safeContext($context);
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

}
