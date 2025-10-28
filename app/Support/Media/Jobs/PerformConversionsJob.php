<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el Job de conversión de medios.
namespace App\Support\Media\Jobs;

// 3. Importaciones de clases necesarias.
use App\Support\Media\Services\MediaCleanupScheduler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob as BasePerformConversionsJob; // 4. Extiende la clase base de Spatie.
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Variante defensiva del job de conversions.
 *
 * Si el registro Media fue eliminado (p. ej. porque el usuario subió otro avatar),
 * el job se descarta silenciosamente para evitar recrear carpetas huérfanas.
 */
class PerformConversionsJob extends BasePerformConversionsJob
{
    /**
     * Límite de reintentos para evitar loops indefinidos en Horizon.
     */
    public int $tries = 5;

    /**
     * Constructor que delega la inicialización a la clase base.
     */
    public function __construct(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing = false,
    ) {
        parent::__construct($conversions, $media, $onlyMissing);
    }

    /**
     * Maneja la ejecución del job de conversión de medios.
     * Verifica si el modelo Media aún existe antes de procesar.
     * Si no existe, limpia posibles archivos huérfanos.
     * Maneja excepciones y reintenta si es necesario.
     *
     * @param FileManipulator $fileManipulator Servicio para realizar las conversiones.
     * @return bool Verdadero si se completó correctamente, falso si se debe reintentar o fallar.
     */
    public function handle(FileManipulator $fileManipulator): bool
    {
        try {
            // 5. Consulta la base de datos para obtener el modelo Media actualizado.
            $freshMedia = Media::query()->find($this->media->getKey());

            // 6. Si el modelo Media ya no existe en la base de datos, se descarta el job.
            if ($freshMedia === null) {
                Log::notice('media.conversions.skipped_missing', [
                    'media_id' => $this->media->getKey(),
                    'collection' => $this->media->collection_name,
                ]);

                // 7. Limpia archivos huérfanos si el modelo ya no existe.
                $this->flushPendingCleanup();

                return true; // 8. Se considera completado con éxito (no hay nada que hacer).
            }

            // 9. Verifica si hay conversiones para procesar.
            if ($this->conversions->isEmpty()) {
                Log::info('media.conversions.no_conversions', [
                    'media_id' => $freshMedia->getKey(),
                    'collection' => $freshMedia->collection_name,
                ]);

                return true; // 10. No hay nada que hacer.
            }

            // 11. Sustituye la instancia serializada por la recién consultada para garantizar coherencia de paths y metadatos.
            $this->media = $freshMedia;

            // 12. Ejecuta las conversiones usando el manipulador de archivos.
            $fileManipulator->performConversions(
                $this->conversions,
                $this->media,
                $this->onlyMissing
            );

            return true; // 13. Conversión completada con éxito.
        } catch (\Throwable $exception) {
            // 14. Registra el error y lo reporta al manejador de excepciones global.
            $this->report($exception);

            $currentAttempt = $this->attempts();

            // 15. Si se alcanzó el número máximo de intentos, limpia archivos y falla definitivamente.
            if ($currentAttempt >= $this->tries) {
                $this->flushPendingCleanup();

                Log::alert('media.conversions.failed_permanently', [
                    'media_id'     => $this->media->getKey(),
                    'collection'   => $this->media->collection_name ?? null,
                    'attempts'     => $currentAttempt,
                    'max_attempts' => $this->tries,
                    'message'      => $exception->getMessage(),
                ]);

                $this->fail($exception);

                return false; // 16. Se marca como fallido.
            }

            Log::warning('media.conversions.retrying', [
                'media_id'      => $this->media->getKey(),
                'collection'    => $this->media->collection_name ?? null,
                'attempts'      => $currentAttempt,
                'max_attempts'  => $this->tries,
                'next_retry_in' => 30,
                'message'       => $exception->getMessage(),
            ]);

            // 17. Si no se ha alcanzado el límite de reintentos, reprograma el job para dentro de 30 segundos.
            $this->release(30);

            return false; // 18. Se marca como fallido temporalmente, pero se reintentará.
        }
    }

    /**
     * Registra la excepción y la reporta al manejador global de errores.
     *
     * @param \Throwable $exception Excepción capturada.
     */
    private function report(\Throwable $exception): void
    {
        Log::error('media.conversions.failed', [
            'media_id' => $this->media->getKey(),
            'collection' => $this->media->collection_name ?? null,
            'message' => $exception->getMessage(),
        ]);

        app(ExceptionHandler::class)->report($exception);
    }

    /**
     * Llama al servicio de limpieza para eliminar archivos huérfanos asociados al ID del medio.
     * Maneja errores de forma segura.
     */
    private function flushPendingCleanup(): void
    {
        try {
            app(MediaCleanupScheduler::class)->flushExpired((string) $this->media->getKey());
        } catch (\Throwable $e) {
            Log::warning('media.conversions.cleanup_flush_failed', [
                'media_id' => $this->media->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
