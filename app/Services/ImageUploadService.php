<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Security\Scanners\ClamAvScanner;
use App\Services\Security\Scanners\YaraScanner;
use App\Services\Upload\Core\QuarantineRepository;
use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\ImageProfile;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Servicio de Subida de Imágenes
 * 
 * Normaliza y adjunta imágenes a modelos que implementan la biblioteca Media Library.
 * 
 * Flujo:
 *  - (Opcional) Escanea el archivo en cuarentena con los escáneres activos
 *  - Procesa la imagen con ImagePipeline
 *  - Adjunta el medio al modelo y guarda metadatos
 *  - Limpia archivos temporales y cuarentena
 * 
 * @package App\Services
 */
final class ImageUploadService
{
    /**
     * Configuración de escaneo desde la configuración
     */
    private array $scanConfig;

    /**
     * Clave de caché para el circuit breaker de fallos de escaneo
     */
    private string $scanCircuitCacheKey;

    /**
     * Número máximo de fallos antes de abrir el circuit breaker
     */
    private int $scanCircuitMaxFailures;

    /**
     * Tiempo de decaimiento del circuit breaker en segundos
     */
    private int $scanCircuitDecaySeconds;

    /**
     * Constructor
     *
     * @param ImagePipeline $pipeline Pipeline de procesamiento de imágenes
     * @param ExceptionHandler $exceptions Manejador de excepciones
     * @param QuarantineRepository $quarantine Repositorio de almacenamiento en cuarentena
     * @param ClamAvScanner $clamScanner Escáner de virus ClamAV
     * @param YaraScanner|null $yaraScanner Escáner de patrones YARA (opcional)
     */
    public function __construct(
        private readonly ImagePipeline $pipeline,
        private readonly ExceptionHandler $exceptions,
        private readonly QuarantineRepository $quarantine,
        private readonly ClamAvScanner $clamScanner,
        private readonly ?YaraScanner $yaraScanner = null,
    ) {
        $this->scanConfig = (array) config('image-pipeline.scan', []);
        $circuit = (array) ($this->scanConfig['circuit_breaker'] ?? []);
        $this->scanCircuitCacheKey = (string) ($circuit['cache_key'] ?? 'image_scan:circuit_failures');
        $this->scanCircuitMaxFailures = max(1, (int) ($circuit['max_failures'] ?? 5));
        $this->scanCircuitDecaySeconds = max(60, (int) ($circuit['decay_seconds'] ?? 900));
    }

    // =========================================================================
    // 🔹 FLUJO PRINCIPAL DE SUBIDA
    // =========================================================================

    /**
     * Sube, procesa y adjunta un archivo de imagen a un modelo MediaOwner.
     *
     * @param MediaOwner $owner El modelo que posee el medio
     * @param UploadedFile $file El archivo de imagen subido
     * @param ImageProfile $profile Configuración del perfil de imagen
     * @return Media La entidad de medio creada
     * 
     * @throws InvalidArgumentException Cuando el archivo es inválido o falla el escaneo
     * @throws \Throwable Cuando ocurre cualquier otro error durante el procesamiento
     */
    public function upload(MediaOwner $owner, UploadedFile $file, ImageProfile $profile): Media
    {
        // Valida el archivo de entrada
        if (!$file->isValid()) {
            throw new InvalidArgumentException(__('media.uploads.invalid_image'));
        }

        $collection = $profile->collection();
        $disk = $profile->disk();
        $result = null;
        $quarantinePath = null;
        $processedFile = $file;

        try {
            // =========================================================================
            // 🔹 FASE DE ESCANEADO DE SEGURIDAD
            // =========================================================================
            
            if ($this->scanFeatureEnabled()) {
                if ($this->isCircuitOpen()) {
                    Log::warning('image_upload.scan_circuit_open', [
                        'max_failures' => $this->scanCircuitMaxFailures,
                    ]);
                    throw new InvalidArgumentException(__('media.uploads.scan_unavailable'));
                }

                // Crea una copia en cuarentena y escanea esa copia
                [$processedFile, $quarantinePath] = $this->createQuarantinedFile($file);

                // Encapsula el escaneo para prevenir la continuidad si falla
                try {
                    $this->runScanners($processedFile, $quarantinePath);
                    $this->resetScanFailures();

                    Log::info('image_upload.scan_passed', [
                        'scanners' => $this->activeScannerKeys(),
                    ]);
                } catch (\Throwable $scanException) {
                    // Limpieza temprana e interrupción del flujo
                    if ($quarantinePath !== null) {
                        $this->quarantine->delete($quarantinePath);
                        $quarantinePath = null;
                    }
                    throw $scanException;
                }
            }

            // =========================================================================
            // 🔹 FASE DE PROCESAMIENTO DE IMAGEN
            // =========================================================================
            
            $result = $this->pipeline->process($processedFile);

            // =========================================================================
            // 🔹 FASE DE ADJUNTO DE MEDIO
            // =========================================================================
            
            // Nombre determinista por hash y colección
            $target = sprintf('%s-%s.%s', $collection, $result->contentHash(), $result->extension());

            // Cabeceras seguras
            $safeFilename = str_replace('"', "'", basename($target));
            $headers = [
                'ACL' => 'private',
                'ContentType' => $result->mime(),
                'ContentDisposition' => sprintf('inline; filename="%s"', $safeFilename),
            ];

            $adder = $owner->addMedia($result->path())
                ->usingFileName($target)
                ->addCustomHeaders($headers)
                ->withCustomProperties([
                    'version'     => $result->contentHash(),
                    'uploaded_at' => now()->toIso8601String(),
                    'mime_type'   => $result->mime(),
                    'width'       => $result->width(),
                    'height'      => $result->height(),
                    'headers'     => $headers,
                ]);

            // Maneja colecciones de archivo único
            if ($profile->isSingleFile() && \method_exists($adder, 'singleFile')) {
                $adder->singleFile();
            }

            // Guarda en la colección de medios
            $media = filled($disk)
                ? $adder->toMediaCollection($collection, $disk)
                : $adder->toMediaCollection($collection);

            return $media;

        } catch (\Throwable $exception) {
            // =========================================================================
            // 🔹 FASE DE MANEJO DE ERRORES
            // =========================================================================
            
            // Contexto del archivo sin información de identificación personal (PII)
            $fileContext = [
                'extension' => $file->getClientOriginalExtension(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
            if (config('app.debug', false)) {
                $fileContext['name_hash'] = hash('sha256', (string) $file->getClientOriginalName());
            }

            $this->report('image_upload.failed', $exception, [
                'model'      => $owner::class,
                'model_id'   => $owner->getKey(),
                'collection' => $collection,
                'disk'       => $disk,
                'file'       => $fileContext,
            ]);

            throw $exception;
        } finally {
            // =========================================================================
            // 🔹 FASE DE LIMPIEZA
            // =========================================================================
            
            // Limpieza de cuarentena
            if ($quarantinePath !== null) {
                $this->quarantine->delete($quarantinePath);
            }

            // Limpieza de artefactos del pipeline
            if ($result !== null) {
                try {
                    $result->cleanup();
                } catch (\Throwable $cleanupException) {
                    $this->report(
                        'image_upload.cleanup_failed',
                        $cleanupException,
                        [
                            'model'      => $owner::class,
                            'model_id'   => $owner->getKey(),
                            'collection' => $collection,
                        ],
                        'warning'
                    );
                }
            }
        }
    }

    // =========================================================================
    // 🔹 MÉTODOS DE ESCANEADO DE SEGURIDAD
    // =========================================================================

    /**
     * Verifica si la característica de escaneo de seguridad está habilitada
     */
    private function scanFeatureEnabled(): bool
    {
        return (bool) ($this->scanConfig['enabled'] ?? false);
    }

    /**
     * Verifica si el circuit breaker está abierto (demasiados fallos de escaneo)
     */
    private function isCircuitOpen(): bool
    {
        if (!$this->scanFeatureEnabled()) {
            return false;
        }
        $failures = (int) Cache::get($this->scanCircuitCacheKey, 0);
        return $failures >= $this->scanCircuitMaxFailures;
    }

    /**
     * Registra un fallo de escaneo en el circuit breaker
     */
    private function recordScanFailure(): void
    {
        $failures = (int) Cache::get($this->scanCircuitCacheKey, 0) + 1;
        Cache::put($this->scanCircuitCacheKey, $failures, $this->scanCircuitDecaySeconds);
    }

    /**
     * Reinicia el contador de fallos de escaneo
     */
    private function resetScanFailures(): void
    {
        Cache::forget($this->scanCircuitCacheKey);
    }

    /**
     * Crea una copia en cuarentena y devuelve [UploadedFile_quarantine, path_quarantine]
     *
     * @param UploadedFile $file Archivo subido original
     * @return array{0: UploadedFile, 1: string} Archivo en cuarentena y su ruta
     */
    private function createQuarantinedFile(UploadedFile $file): array
    {
        $bytes = $this->readUploadedFileOnce($file);
        $path = $this->quarantine->put($bytes);
        unset($bytes);

        $name = $file->getClientOriginalName();
        if (!is_string($name) || $name === '') {
            $name = basename($path);
        }

        $mime = $file->getClientMimeType() ?? $file->getMimeType() ?: null;

        // Archivo para escanear/usar desde cuarentena
        $quarantined = new UploadedFile($path, $name, $mime, $file->getError(), true);

        return [$quarantined, $path];
    }

    /**
     * Lee el archivo original una vez con límite de tamaño configurable
     *
     * @param UploadedFile $file Archivo subido a leer
     * @return string Contenido del archivo como bytes
     * @throws InvalidArgumentException Cuando el archivo excede el límite de tamaño o no es legible
     */
    private function readUploadedFileOnce(UploadedFile $file): string
    {
        $maxSize = (int) config('image-pipeline.max_upload_size', 25 * 1024 * 1024); // 25 MB por defecto
        $size = (int) $file->getSize();
        if ($size > 0 && $size > $maxSize) {
            throw new InvalidArgumentException(__('media.uploads.max_size_exceeded', ['bytes' => $maxSize]));
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_readable($realPath)) {
            throw new InvalidArgumentException(__('media.uploads.source_unreadable'));
        }

        $bytes = file_get_contents($realPath);
        if (!is_string($bytes)) {
            throw new InvalidArgumentException(__('media.uploads.source_unreadable'));
        }

        return $bytes;
    }

    /**
     * Resuelve las instancias de escáneres activos
     *
     * @return list<object> Lista de instancias de escáneres
     */
    private function resolveScanners(): array
    {
        $handlers = array_map('strval', $this->scanConfig['handlers'] ?? []);
        $map = [
            ClamAvScanner::class => $this->clamScanner,
            YaraScanner::class   => $this->yaraScanner,
        ];

        $instances = [];
        foreach ($handlers as $handler) {
            if (isset($map[$handler]) && $map[$handler] !== null) {
                $instances[] = $map[$handler];
            }
        }

        return array_values($instances);
    }

    /**
     * Ejecuta los escáneres activos en el archivo en cuarentena
     * Distingue entre fallo técnico y detección de malware
     *
     * @param UploadedFile $file Archivo a escanear
     * @param string $path Ruta del archivo para contexto
     * @throws InvalidArgumentException Cuando falla el escaneo o se detecta malware
     */
    private function runScanners(UploadedFile $file, string $path): void
    {
        $scanners = $this->resolveScanners();

        if ($scanners === []) {
            // La falta de manejadores es un fallo técnico
            $this->recordScanFailure();
            Log::error('image_upload.scan_missing_handlers');
            throw new InvalidArgumentException(__('media.uploads.scan_unavailable'));
        }

        $context = [
            'path' => $path,
            'is_first_chunk' => true,
            'timeout_ms' => (int) ($this->scanConfig['timeout_ms'] ?? 5000),
        ];

        foreach ($scanners as $scanner) {
            try {
                $clean = $scanner($file, $context);
            } catch (\Throwable $e) {
                // Fallo técnico del escáner: abre el circuit breaker
                $this->recordScanFailure();
                Log::error('image_upload.scanner_failure', [
                    'scanner' => $this->scannerName($scanner),
                    'error'   => $e->getMessage(),
                ]);
                throw new InvalidArgumentException(__('media.uploads.scan_unavailable'));
            }

            if (!$clean) {
                // Malware detectado correctamente: NO abre el circuit breaker
                Log::warning('image_upload.scan_blocked', [
                    'scanner' => $this->scannerName($scanner),
                ]);
                throw new InvalidArgumentException(__('media.uploads.scan_blocked'));
            }
        }
    }

    /**
     * Obtiene los nombres de los escáneres activos para el registro
     *
     * @return array Lista de nombres de escáneres activos
     */
    private function activeScannerKeys(): array
    {
        if (!$this->scanFeatureEnabled()) {
            return [];
        }

        return array_map(
            fn(object $scanner) => $this->scannerName($scanner),
            $this->resolveScanners()
        );
    }

    /**
     * Obtiene el nombre de la clase del escáner sin el namespace
     *
     * @param object $scanner Instancia del escáner
     * @return string Nombre base de la clase del escáner en minúsculas
     */
    private function scannerName(object $scanner): string
    {
        return strtolower(class_basename($scanner));
    }

    // =========================================================================
    // 🔹 MANEJO DE ERRORES Y REGISTRO
    // =========================================================================

    /**
     * Registra el error y lo reporta al manejador de excepciones
     *
     * @param string $message Mensaje de registro
     * @param \Throwable $exception Excepción a reportar
     * @param array $context Contexto adicional para el registro
     * @param string $level Nivel de registro
     */
    private function report(
        string $message,
        \Throwable $exception,
        array $context = [],
        string $level = 'error'
    ): void {
        $logContext = array_merge([
            'exception' => $exception->getMessage(),
            'exception_class' => \get_class($exception),
        ], $context);

        if (config('app.debug', false)) {
            $logContext['trace'] = $exception->getTraceAsString();
        }

        Log::log($level, $message, $logContext);
        $this->exceptions->report($exception);
    }
}
