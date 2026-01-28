<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el Job de conversión de medios.
namespace App\Infrastructure\Uploads\Pipeline\Jobs;

// 3. Importaciones de clases necesarias.
use App\Application\Shared\Contracts\LoggerInterface; // Logger desacoplado; ej. info/warning
use App\Infrastructure\Tenancy\Models\Tenant; // Modelo Tenant para makeCurrent; ej. Tenant #3
use App\Infrastructure\Uploads\Core\Contracts\MediaCleanupScheduler; // Scheduler de limpieza; ej. flushExpired
use Illuminate\Contracts\Debug\ExceptionHandler; // Reporta excepciones; ej. app(ExceptionHandler)
use Illuminate\Support\Facades\Storage; // Acceso a disks; ej. Storage::disk('public')->exists('x.jpg')
use Spatie\MediaLibrary\Conversions\ConversionCollection; // Lista de conversions; ej. ['thumb']
use Spatie\MediaLibrary\Conversions\FileManipulator; // Ejecuta conversions; ej. performConversions()
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob as BasePerformConversionsJob; // Extiende base Spatie; ej. job original
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media; ej. Media #5

/**
 * Variante defensiva del job de conversions.
 *
 * Caso típico: el usuario sube avatar y lo elimina/cambia rápido.
 * El Media puede existir o no, y/o el fichero puede haber desaparecido antes de convertir.
 * En esos casos, el job se descarta limpio (sin reintentos) para evitar FAILs en cascada.
 */
class PerformConversionsJob extends BasePerformConversionsJob
{
    /**
     * Límite de reintentos (para errores reales, no para “missing file”).
     * Nota: si quieres menos ruido en local, ponlo a 1.
     */
    public int $tries = 1; // Ej. 5

    /**
     * Backoff para reintentos cuando el worker/horizon gestione retries.
     * Si tu worker usa --tries=1, esto no aplica.
     */
    public int|array $backoff = 30; // Ej. 30s

    /**
     * Constructor que delega la inicialización a la clase base.
     */
    public function __construct(
        ConversionCollection $conversions, // Conversions a ejecutar; ej. ['thumb','medium']
        Media $media, // Media objetivo; ej. Media #1863
        bool $onlyMissing = false, // Solo conversions faltantes; ej. false
        public int|string|null $tenantId = null, // Tenant para payload; ej. 1
    ) {
        parent::__construct($conversions, $media, $onlyMissing); // Llama al constructor base; setea media/conversions

        $this->tenantId ??= $media->getCustomProperty('tenant_id') ?? tenant()?->getKey(); // Fallback tenant; ej. 1
    }

    /**
     * Maneja la ejecución del job de conversión de medios.
     *
     * - Si el Media ya no existe => skip limpio + cleanup.
     * - Si el fichero origen ya no existe => skip limpio + cleanup.
     * - Si hay excepción de “missing file/dir” => skip limpio + cleanup.
     * - Si hay excepción real => se reporta y se deja fallar (para retry estándar del worker).
     *
     * @param FileManipulator $fileManipulator Servicio para realizar las conversiones.
     * @return bool true = procesado/descartado con éxito.
     */
    public function handle(FileManipulator $fileManipulator): bool
    {
        if (!$this->ensureTenantContext()) { // Intenta fijar tenant antes de procesar
            return true; // Si no hay tenant, termina sin error para evitar bucles
        }

        try {
            // 1) Refresca el Media desde BD (evita usar el modelo serializado).
            $freshMedia = Media::query()->find($this->media->getKey()); // Ej. Media::find(1863)

            // 2) Si el Media ya no existe, descarta el job.
            if ($freshMedia === null) {
                $this->logger()->notice('media.conversions.skipped_missing', [ // Log skip
                    'media_id' => $this->media->getKey(), // Ej. 1863
                    'collection' => $this->media->collection_name, // Ej. 'avatar'
                ]);

                $this->flushPendingCleanup(); // Limpia restos; ej. conversions/responsive-images
                return true; // Termina OK
            }

            // 3) Si no hay conversions, termina.
            if ($this->conversions->isEmpty()) {
                $this->logger()->info('media.conversions.no_conversions', [ // Log info
                    'media_id' => $freshMedia->getKey(), // Ej. 1863
                    'collection' => $freshMedia->collection_name, // Ej. 'avatar'
                ]);

                return true; // Nada que hacer
            }

            // 4) NUEVO: si el fichero original ya no existe (carrera con DELETE), descarta limpio.
            if (!$this->sourceFileExists($freshMedia)) {
                $this->logger()->notice('media.conversions.skipped_source_missing', [ // Log skip
                    'media_id' => $freshMedia->getKey(), // Ej. 1863
                    'disk' => $freshMedia->disk, // Ej. 'public'
                    'path' => $this->safeRelativePath($freshMedia), // Ej. 'tenants/1/.../file.jpg'
                ]);

                $this->flushPendingCleanup(); // Limpia restos
                return true; // Importante: NO FAIL, NO RETRY
            }

            // 5) Reemplaza la instancia por la fresca para coherencia de paths/metadatos.
            $this->media = $freshMedia; // Ej. Media actualizado

            // 6) Ejecuta conversiones.
            $fileManipulator->performConversions(
                $this->conversions, // Ej. ['thumb','medium','large']
                $this->media, // Ej. Media #1863
                $this->onlyMissing // Ej. false
            );

            return true; // OK
        } catch (\Throwable $exception) {
            // 1) Revalidación: si durante la conversión el Media o el fichero desaparecieron, descartamos limpio.
            $mediaId = $this->media->getKey();

            $freshAfterException = Media::query()->find($mediaId);

            if ($freshAfterException === null || !$this->sourceFileExists($freshAfterException)) {
                $this->logger()->notice('media.conversions.skipped_disappeared_during_processing', [
                    'media_id'    => $mediaId,
                    'collection'  => $this->media->collection_name ?? null,
                    'disk'        => $this->media->disk ?? null,
                    'message'     => $exception->getMessage(),
                ]);

                $this->flushPendingCleanup();
                return true; // IMPORTANT: no FAIL, no retry
            }

            // 2) Si el error encaja en “missing file/dir”, también descartamos limpio.
            if ($this->isMissingFileException($exception)) {
                $this->logger()->notice('media.conversions.skipped_missing_runtime', [
                    'media_id'   => $mediaId,
                    'collection' => $this->media->collection_name ?? null,
                    'message'    => $exception->getMessage(),
                ]);

                $this->flushPendingCleanup();
                return true;
            }

            // 3) Error real: se reporta y se deja fallar.
            $this->report($exception);
            throw $exception;
        }
    }

    /**
     * Comprueba si el fichero origen del media existe en su disk.
     */
    private function sourceFileExists(Media $media): bool
    {
        $disk = (string) $media->disk; // Disk; ej. 'public'

        $relative = $this->safeRelativePath($media); // Path relativo; ej. 'tenants/1/.../file.jpg'
        if ($relative === null || $relative === '') {
            return true; // Si no podemos resolver ruta, no bloqueamos conversiones legítimas
        }

        return Storage::disk($disk)->exists($relative); // Exists; ej. false si ya se borró
    }

    /**
     * Devuelve path relativo si el modelo lo soporta.
     */
    private function safeRelativePath(Media $media): ?string
    {
        if (method_exists($media, 'getPathRelativeToRoot')) { // Spatie v11; ej. true
            return (string) $media->getPathRelativeToRoot(); // Ej. 'tenants/1/.../file.jpg'
        }

        return null; // Sin método; ej. null
    }

    /**
     * Heurística para identificar excepciones típicas de fichero/carpeta inexistente.
     * (Incluye Flysystem y mensajes habituales de PHP/Imagick/Intervention).
     */
    private function isMissingFileException(\Throwable $e): bool
    {
        // Flysystem v3: UnableToReadFile suele indicar “missing”.
        if (class_exists(\League\Flysystem\UnableToReadFile::class) && $e instanceof \League\Flysystem\UnableToReadFile) {
            return true; // Ej. true
        }

        $m = $e->getMessage(); // Mensaje; ej. "No such file or directory"

        return str_contains($m, 'No such file or directory') // Linux; ej. true
            || str_contains($m, 'failed to open stream') // PHP streams; ej. true
            || str_contains($m, 'Unable to read') // Genérico lectura; ej. true
            || str_contains($m, 'Unable to load') // Imagen no cargable; ej. true
            || str_contains($m, 'not found'); // Genérico; ej. true
    }

    /**
     * Registra la excepción y la reporta al manejador global de errores.
     */
    private function report(\Throwable $exception): void
    {
        $this->logger()->error('media.conversions.failed', [ // Log error
            'media_id' => $this->media->getKey(), // Ej. 1863
            'collection' => $this->media->collection_name ?? null, // Ej. 'avatar'
            'message' => $exception->getMessage(), // Ej. "ImagickException..."
        ]);

        app(ExceptionHandler::class)->report($exception); // Report; ej. Sentry/bugsnag
    }

    /**
     * Llama al servicio de limpieza para eliminar archivos huérfanos asociados al ID del medio.
     * Maneja errores de forma segura.
     */
    private function flushPendingCleanup(): void
    {
        try {
            app(MediaCleanupScheduler::class)->flushExpired((string) $this->media->getKey()); // Ej. flushExpired('1863')
        } catch (\Throwable $e) {
            $this->logger()->warning('media.conversions.cleanup_flush_failed', [ // Log warning
                'media_id' => $this->media->getKey(), // Ej. 1863
                'error' => $e->getMessage(), // Ej. "Redis timeout"
            ]);
        }
    }

    /**
     * Obtiene logger desacoplado.
     */
    private function logger(): LoggerInterface
    {
        return app(LoggerInterface::class); // Ej. logger custom
    }

    /**
     * Garantiza que el tenant esté en contexto antes de procesar.
     */
    private function ensureTenantContext(): bool
    {
        $tenantId = $this->tenantId ?? $this->media->getCustomProperty('tenant_id'); // Resuelve tenantId; ej. 1

        if ($tenantId === null) {
            $this->logger()->warning('media.conversions.missing_tenant', [ // Log warning
                'media_id' => $this->media->getKey(), // Ej. 1863
                'collection' => $this->media->collection_name ?? null, // Ej. 'avatar'
            ]);
            return false; // Evita procesar sin tenant
        }

        $tenant = Tenant::query()->find($tenantId); // Busca tenant; ej. Tenant #1
        if ($tenant === null) {
            $this->logger()->warning('media.conversions.tenant_not_found', [ // Log warning
                'tenant_id' => $tenantId, // Ej. 99
                'media_id' => $this->media->getKey(), // Ej. 1863
            ]);
            return false; // Evita procesar sin tenant válido
        }

        $tenant->makeCurrent(); // Fija tenant actual; ej. tenant()->id = 1
        return true; // OK
    }
}
