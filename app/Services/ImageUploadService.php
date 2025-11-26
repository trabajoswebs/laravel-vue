<?php

declare(strict_types=1);

namespace App\Services;

// Importamos las clases necesarias para el servicio de subida de imágenes
use App\Services\Security\Scanners\ClamAvScanner; // Escáner de antivirus ClamAV
use App\Services\Security\Scanners\YaraScanner; // Escáner de firmas YARA
use App\Services\Upload\Contracts\UploadPipeline; // Contrato para el pipeline de subida
use App\Services\Upload\Contracts\UploadResult; // Contrato para el resultado de subida
use App\Services\Upload\Contracts\UploadService; // Contrato para el servicio de subida
use App\Services\Upload\Core\QuarantineRepository; // Repositorio para manejar cuarentena
use App\Services\Upload\Exceptions\NormalizationFailedException; // Excepción para fallo de normalización
use App\Services\Upload\Exceptions\QuarantineException; // Excepción para fallo de cuarentena
use App\Services\Upload\Exceptions\ScanFailedException; // Excepción para fallo de escaneo
use App\Services\Upload\Exceptions\UploadValidationException; // Excepción para validación de subida
use App\Services\Upload\Exceptions\VirusDetectedException; // Excepción para detección de virus
use App\Support\Media\Contracts\MediaOwner; // Contrato para modelos que poseen media
use App\Support\Media\ImageProfile; // Perfil de imagen para subida
use Illuminate\Cache\RedisStore; // Store de Redis para cache
use Illuminate\Contracts\Cache\LockTimeoutException; // Excepción para timeout de lock
use Illuminate\Contracts\Debug\ExceptionHandler; // Manejador de excepciones
use Illuminate\Http\UploadedFile; // Clase para manejar archivos subidos
use Illuminate\Support\Facades\Cache; // Facade para manejar cache
use Illuminate\Support\Facades\Log; // Facade para registrar logs
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo de media de Spatie

/**
 * Servicio de subida y procesamiento seguro de imágenes.
 *
 * Flujo:
 *  - Valida el archivo subido (estado, MIME, tamaño).
 *  - (Opcional) duplica en cuarentena y ejecuta escáneres (ClamAV/YARA).
 *  - Ejecuta el pipeline de normalización (resize, formatos, etc.).
 *  - Adjunta el artefacto normalizado al modelo MediaOwner (Media Library).
 *  - Limpia cuarentena y artefactos temporales (UploadResult->path).
 */
final class ImageUploadService
{
    /**
     * Configuración de escaneo.
     *
     * @var array<string,mixed>
     */
    private array $scanConfig;

    /**
     * Clave de caché para el circuit breaker de fallos de escaneo.
     */
    private string $scanCircuitCacheKey;

    /**
     * Número máximo de fallos técnicos antes de abrir el circuit breaker.
     * (Las detecciones de malware NO cuentan como fallo técnico).
     */
    private int $scanCircuitMaxFailures;

    /**
     * Tiempo de decaimiento del circuit breaker en segundos.
     */
    private int $scanCircuitDecaySeconds;

    /**
     * Constructor del servicio de subida de imágenes.
     *
     * @param UploadPipeline $pipeline Pipeline de subida para normalizar imágenes
     * @param UploadService $uploadService Servicio de subida para adjuntar archivos
     * @param ExceptionHandler $exceptions Manejador de excepciones
     * @param QuarantineRepository $quarantine Repositorio de cuarentena
     * @param ClamAvScanner $clamScanner Escáner ClamAV
     * @param YaraScanner|null $yaraScanner Escáner YARA (opcional)
     */
    public function __construct(
        private readonly UploadPipeline $pipeline, // Pipeline para procesar imágenes
        private readonly UploadService $uploadService, // Servicio para adjuntar archivos
        private readonly ExceptionHandler $exceptions, // Manejador de excepciones
        private readonly QuarantineRepository $quarantine, // Repositorio de cuarentena
        private readonly ClamAvScanner $clamScanner, // Escáner ClamAV
        private readonly ?YaraScanner $yaraScanner = null, // Escáner YARA (opcional)
    ) {
        // Cargamos la configuración de escaneo desde config/image-pipeline.php
        $this->scanConfig = (array) config('image-pipeline.scan', []);

        // Configuramos el circuit breaker para fallos de escaneo
        $circuit = (array) ($this->scanConfig['circuit_breaker'] ?? []);
        $this->scanCircuitCacheKey    = (string) ($circuit['cache_key'] ?? 'image_scan:circuit_failures');
        $this->scanCircuitMaxFailures = max(1, (int) ($circuit['max_failures'] ?? 5));
        $this->scanCircuitDecaySeconds = max(60, (int) ($circuit['decay_seconds'] ?? 900));
    }

    // =========================================================================
    // 🔹 FLUJO PRINCIPAL DE SUBIDA
    // =========================================================================

    /**
     * Sube, procesa y adjunta un archivo de imagen a un modelo MediaOwner.
     *
     * @param  MediaOwner    $owner   Modelo que poseerá el media.
     * @param  UploadedFile  $file    Archivo de imagen original subido.
     * @param  ImageProfile  $profile Perfil de imagen (colección, disco, singleFile, etc.).
     * @return Media                  Media de Spatie creado/actualizado.
     *
     * @throws UploadValidationException Si el archivo no es válido
     * @throws VirusDetectedException Si se detecta malware
     * @throws NormalizationFailedException Si falla la normalización
     * @throws ScanFailedException Si falla el escaneo
     * @throws QuarantineException Si falla la cuarentena
     * @throws \Throwable Para otros errores
     */
    public function upload(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): Media
    {
        // Validamos que el archivo subido sea válido
        if (! $file->isValid()) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        // Validamos el tipo MIME del archivo
        $this->validateMimeType($file);

        $collection     = $profile->collection(); // Colección donde se guardará el archivo
        $disk           = $profile->disk(); // Disco donde se guardará
        $quarantinePath = null; // Ruta en cuarentena (inicialmente vacía)
        $processedFile  = $file; // Archivo procesado (inicialmente el original)
        $artifact       = null; // Artefacto resultante del pipeline

        try {
            // Si el escaneo de seguridad está habilitado
            if ($this->scanFeatureEnabled()) {
                // Verificamos si el circuit breaker está abierto
                if ($this->isCircuitOpen()) {
                    Log::warning('image_upload.scan_circuit_open', [
                        'max_failures' => $this->scanCircuitMaxFailures,
                    ]);

                    throw new ScanFailedException(__('media.uploads.scan_unavailable'));
                }

                // Duplica en cuarentena y escanea el artefacto.
                [$processedFile, $quarantinePath] = $this->createQuarantinedFile($file);

                try {
                    // Ejecutamos los escáneres de seguridad
                    $this->runScanners($processedFile, $quarantinePath);
                    // Si todo va bien, reiniciamos el contador de fallos
                    $this->resetScanFailures();

                    Log::info('image_upload.scan_passed', [
                        'scanners' => $this->activeScannerKeys(),
                    ]);
                } catch (\Throwable $scanException) {
                    // Si falla el escaneo, intentamos limpiar cuarentena
                    if ($quarantinePath !== null) {
                        $this->quarantine->delete($quarantinePath);
                        $quarantinePath = null;
                    }

                    throw $scanException;
                }
            }

            // Pipeline de normalización: devuelve un UploadResult con ruta temporal.
            $artifact = $this->pipeline->process($processedFile);

            // Adjunta el artefacto al modelo usando Media Library.
            $media = $this->uploadService->attach(
                $owner,
                $artifact,
                $collection,
                $disk,
                $profile->isSingleFile(), // Indica si es un archivo único (reemplaza el anterior)
            );

            return $media;
        } catch (\Throwable $exception) {
            // Creamos contexto de información del archivo para el log
            $fileContext = [
                'extension' => $file->getClientOriginalExtension(),
                'size'      => $file->getSize(),
                'mime'      => $file->getMimeType(),
            ];

            // En modo debug, añadimos hash del nombre para identificación
            if (config('app.debug', false)) {
                $fileContext['name_hash'] = hash('sha256', (string) $file->getClientOriginalName());
            }

            // Registramos el error
            $this->report('image_upload.failed', $exception, [
                'model'      => $owner::class,
                'model_id'   => $owner->getKey(),
                'collection' => $collection,
                'disk'       => $disk,
                'file'       => $fileContext,
            ]);

            throw $exception;
        } finally {
            // Borrar siempre el artefacto de cuarentena (si existe).
            if ($quarantinePath !== null) {
                $this->quarantine->delete($quarantinePath);
            }

            // Limpia el archivo temporal propio del UploadResult.
            // Contractual: UploadResult->path debe representar un artefacto temporal.
            if ($artifact instanceof UploadResult) {
                $this->cleanupArtifactPath($artifact);
            }
        }
    }

    // =========================================================================
    // 🔹 MÉTODOS DE ESCANEADO DE SEGURIDAD
    // =========================================================================

    /**
     * Verifica si la característica de escaneo de seguridad está habilitada.
     *
     * @return bool True si el escaneo está habilitado
     */
    private function scanFeatureEnabled(): bool
    {
        return (bool) ($this->scanConfig['enabled'] ?? false);
    }

    /**
     * Valida el MIME antes de copiar a cuarentena.
     *
     * @param UploadedFile $file Archivo a validar
     * @throws UploadValidationException Si el MIME no es válido
     */
    private function validateMimeType(UploadedFile $file): void
    {
        // Obtenemos los MIMEs permitidos y prohibidos desde la configuración
        $allowedMimes    = array_keys((array) config('image-pipeline.allowed_mimes', []));
        $disallowedMimes = (array) config('image-pipeline.disallowed_mimes', []);
        $mime            = $file->getMimeType();

        // Si no podemos obtener el MIME, es inválido
        if (! is_string($mime)) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        // Modo whitelist: si hay allowed, solo se permite lo enumerado.
        if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        // Si está en la lista de prohibidos, es inválido
        if (in_array($mime, $disallowedMimes, true)) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }
    }

    /**
     * Verifica si el circuit breaker está abierto (demasiados fallos técnicos de escaneo).
     *
     * Nota: detecciones de malware NO cuentan como fallo técnico, sólo errores de infraestructura
     * (fallos en ClamAV/YARA, falta de handlers, timeouts, etc.).
     *
     * @return bool True si el circuit breaker está abierto
     */
    private function isCircuitOpen(): bool
    {
        if (! $this->scanFeatureEnabled()) {
            return false;
        }

        // Obtenemos el número de fallos desde la cache
        $failures = (int) Cache::get($this->scanCircuitCacheKey, 0);

        // Si supera el máximo permitido, el circuito está abierto
        return $failures >= $this->scanCircuitMaxFailures;
    }

    /**
     * Registra un fallo técnico de escaneo en el circuit breaker.
     *
     * No se usa para detecciones de malware, sólo para fallos de infraestructura.
     */
    private function recordScanFailure(): void
    {
        $store = Cache::getStore();

        // Si usamos Redis, podemos usar operaciones atómicas
        if ($store instanceof RedisStore) {
            Cache::increment($this->scanCircuitCacheKey, 1);

            try {
                // Actualizamos el TTL del contador de fallos
                $store->connection()->expire($this->scanCircuitCacheKey, $this->scanCircuitDecaySeconds);
            } catch (\Throwable $e) {
                Log::debug('image_upload.circuit_ttl_refresh_failed', ['error' => $e->getMessage()]);
            }

            return;
        }

        // Para otros stores, usamos locks para evitar condiciones de carrera
        $lock = Cache::lock("{$this->scanCircuitCacheKey}:lock", 5);

        try {
            // Intentamos obtener el lock y actualizar el contador
            $lock->block(2, function (): void {
                $failures = (int) Cache::get($this->scanCircuitCacheKey, 0) + 1;
                Cache::put($this->scanCircuitCacheKey, $failures, $this->scanCircuitDecaySeconds);
            });
        } catch (LockTimeoutException $e) {
            // Si falla el lock, actualizamos directamente
            $failures = (int) Cache::get($this->scanCircuitCacheKey, 0) + 1;
            Cache::put($this->scanCircuitCacheKey, $failures, $this->scanCircuitDecaySeconds);
        }
    }

    /**
     * Reinicia el contador de fallos de escaneo (cierra el circuit breaker).
     */
    private function resetScanFailures(): void
    {
        // Eliminamos la clave de cache que contaba los fallos
        Cache::forget($this->scanCircuitCacheKey);
    }

    /**
     * Crea una copia en cuarentena y devuelve [UploadedFile_quarantine, path_quarantine].
     *
     * @param UploadedFile $file Archivo original a cuarentenar
     * @return array{0:UploadedFile,1:string} Array con el archivo en cuarentena y su ruta
     *
     * @throws UploadValidationException Si el archivo no es válido
     * @throws QuarantineException Si falla la cuarentena
     */
    private function createQuarantinedFile(UploadedFile $file): array
    {
        // Obtenemos el tamaño máximo permitido desde la configuración
        $maxSize = (int) config('image-pipeline.max_upload_size', 25 * 1024 * 1024);
        $size    = (int) $file->getSize();

        // Verificamos que no exceda el tamaño máximo
        if ($size > 0 && $size > $maxSize) {
            throw new UploadValidationException(__('media.uploads.max_size_exceeded', ['bytes' => $maxSize]));
        }

        // Obtenemos la ruta real del archivo original
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! is_readable($realPath)) {
            throw new UploadValidationException(__('media.uploads.source_unreadable'));
        }

        // Abrimos el archivo para leerlo
        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new UploadValidationException(__('media.uploads.source_unreadable'));
        }

        try {
            // Creamos una copia en el repositorio de cuarentena
            // La implementación concreta (LocalQuarantineRepository) devolverá normalmente una ruta absoluta.
            $path = $this->quarantine->putStream($handle);
        } finally {
            // Cerramos el handle para liberar recursos
            fclose($handle);
        }

        // Obtenemos el nombre original del archivo
        $name = $file->getClientOriginalName();
        if (! is_string($name) || $name === '') {
            $name = basename($path);
        }

        // Obtenemos el MIME del cliente o del archivo
        $mime = $file->getClientMimeType() ?? $file->getMimeType() ?: null;

        // Creamos un nuevo UploadedFile apuntando al artefacto en cuarentena
        $quarantined = new UploadedFile($path, $name, $mime, $file->getError(), true);

        return [$quarantined, $path];
    }

    /**
     * Resuelve las instancias de escáneres activos.
     *
     * @return list<callable(UploadedFile,array<string,mixed>):bool>  Escáneres invocables.
     */
    private function resolveScanners(): array
    {
        // Obtenemos los handlers configurados
        $handlers = array_map('strval', $this->scanConfig['handlers'] ?? []);

        // Mapeamos los handlers a sus instancias
        $map = [
            ClamAvScanner::class => $this->clamScanner, // Escáner ClamAV
            YaraScanner::class   => $this->yaraScanner, // Escáner YARA
        ];

        $instances = [];

        // Iteramos sobre los handlers configurados
        foreach ($handlers as $handler) {
            // Si el handler está mapeado y no es null, lo añadimos
            if (isset($map[$handler]) && $map[$handler] !== null) {
                // Cada scanner debe implementar __invoke(UploadedFile $file, array $context): bool
                $instances[] = $map[$handler];
            }
        }

        return array_values($instances);
    }

    /**
     * Ejecuta los escáneres activos sobre el archivo en cuarentena.
     *
     * - Si un escáner lanza excepción → fallo técnico → circuit breaker + ScanFailedException.
     * - Si un escáner devuelve false → malware detectado → VirusDetectedException (NO abre el circuito).
     *
     * @param UploadedFile $file Archivo a escanear
     * @param string $path Ruta del archivo en cuarentena
     * @throws ScanFailedException Si falla el escaneo
     * @throws VirusDetectedException Si se detecta malware
     */
    private function runScanners(UploadedFile $file, string $path): void
    {
        $scanners = $this->resolveScanners();

        if ($scanners === []) {
            // Falta de handlers = fallo técnico de infraestructura.
            $this->recordScanFailure();

            Log::error('image_upload.scan_missing_handlers');

            throw new ScanFailedException(__('media.uploads.scan_unavailable'));
        }

        // Contexto para los escáneres
        $context = [
            'path'          => $path,
            'is_first_chunk' => true,
            'timeout_ms'    => (int) ($this->scanConfig['timeout_ms'] ?? 5000),
        ];

        foreach ($scanners as $scanner) {
            try {
                /** @var bool $clean */
                // Ejecutamos el escáner
                $clean = $scanner($file, $context);
            } catch (\Throwable $e) {
                // Fallo técnico del escáner → registramos fallo en circuit breaker.
                $this->recordScanFailure();

                Log::error('image_upload.scanner_failure', [
                    'scanner' => $this->scannerName($scanner),
                    'error'   => $e->getMessage(),
                ]);

                throw new ScanFailedException(__('media.uploads.scan_unavailable'));
            }

            if (! $clean) {
                // Malware detectado: no tocamos el circuit breaker.
                Log::warning('image_upload.scan_blocked', [
                    'scanner' => $this->scannerName($scanner),
                ]);

                throw new VirusDetectedException(__('media.uploads.scan_blocked'));
            }
        }
    }

    /**
     * Nombres de escáneres activos para logging.
     *
     * @return list<string> Array con los nombres de los escáneres activos
     */
    private function activeScannerKeys(): array
    {
        if (! $this->scanFeatureEnabled()) {
            return [];
        }

        return array_map(
            static fn(object $scanner): string => self::scannerName($scanner),
            $this->resolveScanners(),
        );
    }

    /**
     * Obtiene el nombre de la clase del escáner sin namespace.
     *
     * @param object $scanner Instancia del escáner
     * @return string Nombre del escáner en minúsculas
     */
    private static function scannerName(object $scanner): string
    {
        return strtolower(class_basename($scanner));
    }

    /**
     * Elimina el artefacto temporal propio del UploadResult.
     *
     * Contractual:
     *  - UploadResult::path debe apuntar a un archivo temporal ya consumido por Media Library.
     *
     * @param UploadResult|null $artifact Artefacto a limpiar
     */
    private function cleanupArtifactPath(?UploadResult $artifact): void
    {
        if ($artifact === null) {
            return;
        }

        // Si el path es string y existe como archivo, lo eliminamos
        if (is_string($artifact->path) && is_file($artifact->path)) {
            @unlink($artifact->path); // @ para evitar errores si no se puede eliminar
        }
    }

    // =========================================================================
    // 🔹 MANEJO DE ERRORES Y REGISTRO
    // =========================================================================

    /**
     * Registra el error y lo reporta al manejador de excepciones.
     *
     * @param  string     $message   Mensaje base de log.
     * @param  \Throwable $exception Excepción capturada.
     * @param  array<string,mixed> $context  Contexto adicional.
     * @param  string     $level    Nivel de log (error, warning, etc.).
     */
    private function report(
        string $message,
        \Throwable $exception,
        array $context = [],
        string $level = 'error',
    ): void {
        // Creamos el contexto completo para el log
        $logContext = array_merge([
            'exception'       => $exception->getMessage(),
            'exception_class' => \get_class($exception),
        ], $context);

        // En modo debug, añadimos el stack trace
        if (config('app.debug', false)) {
            $logContext['trace'] = $exception->getTraceAsString();
        }

        // Registramos en el log y reportamos al manejador de excepciones
        Log::log($level, $message, $logContext);
        $this->exceptions->report($exception);
    }
}
