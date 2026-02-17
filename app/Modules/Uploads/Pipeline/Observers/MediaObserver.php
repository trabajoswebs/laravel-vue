<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para el observador de modelos Media.
namespace App\Modules\Uploads\Pipeline\Observers;

// 3. Importaciones de clases necesarias.
use App\Modules\Uploads\Contracts\MediaCleanupScheduler;
use App\Modules\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use App\Modules\Uploads\Pipeline\Jobs\CleanupAvatarOrphans;
use App\Modules\Uploads\Pipeline\Support\MediaCleanupArtifactsBuilder;
use App\Support\Logging\SecurityLogger;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Observador para el modelo Media.
 * Se encarga de limpiar archivos relacionados cuando un modelo Media es eliminado.
 */
final class MediaObserver
{
    /**
     * Se ejecuta después de que un modelo Media es eliminado (soft delete).
     *
     * @param Media $media El modelo Media eliminado.
     */
    public function deleted(Media $media): void
    {
        $this->handleDeleted($media, 'deleted');
    }

    /**
     * Se ejecuta después de que un modelo Media es eliminado permanentemente (force delete).
     *
     * @param Media $media El modelo Media eliminado permanentemente.
     */
    public function forceDeleted(Media $media): void
    {
        $this->handleDeleted($media, 'force_deleted');
    }

    private function handleDeleted(Media $media, string $event): void
    {
        $this->dispatchDirectCleanup($media, $event . '_event');
        if ($this->shouldDispatchOrphanCleanup($media)) {
            CleanupAvatarOrphans::dispatch($media->getCustomProperty('tenant_id') ?? tenant()?->getKey(), $media->model_id);
        }
        $this->flushPendingCleanup($media, $event);
    }

    /**
     * Obtiene el ID del modelo Media y llama al servicio de limpieza.
     * Maneja errores al resolver el servicio o al ejecutar la limpieza.
     *
     * @param Media  $media El modelo Media.
     * @param string $event El evento que disparó la limpieza ('deleted' o 'force_deleted').
     */
    private function flushPendingCleanup(Media $media, string $event): void
    {
        // Se convierte el ID del modelo Media a string para garantizar consistencia.
        $mediaId = (string) $media->getKey();

        // Intenta resolver el servicio MediaCleanupScheduler.
        try {
            $scheduler = app(MediaCleanupScheduler::class);
        } catch (\Throwable $e) {
            // Registra un error si no se puede resolver el servicio.
            SecurityLogger::warning('media.cleanup.observer_scheduler_resolve_failed', [
                'media_id' => $mediaId,
                'event'    => $event,
                'error'    => $e->getMessage(),
            ]);
            // Si no se puede resolver el servicio, se interrumpe la ejecución del método.
            return;
        }

        // Intenta ejecutar la limpieza de archivos asociados al ID del modelo Media.
        try {
            $scheduler->flushExpired($mediaId);
        } catch (\Throwable $e) {
            // Registra un error si la limpieza falla.
            SecurityLogger::warning('media.cleanup.observer_flush_failed', [
                'media_id' => $mediaId,
                'event'    => $event,
                'error'    => $e->getMessage(),
            ]);
            // No se interrumpe la ejecución aquí, ya que el error es solo de limpieza.
        }
    }

    /**
     * Encola cleanup inmediato con paths derivados del media que se está eliminando.
     */
    private function dispatchDirectCleanup(Media $media, string $reason): void
    {
        try {
            $artifacts = app(MediaCleanupArtifactsBuilder::class)->forMedia($media);
            if ($artifacts === []) {
                return;
            }

            CleanupMediaArtifactsJob::dispatch($artifacts, []);

            SecurityLogger::info('media.cleanup.direct_dispatched', [
                'media_id' => (string) $media->getKey(),
                'reason'   => $reason,
                'disks'    => array_keys($artifacts),
            ]);
        } catch (\Throwable $e) {
            SecurityLogger::warning('media.cleanup.direct_dispatch_failed', [
                'media_id' => (string) $media->getKey(),
                'reason'   => $reason,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function shouldDispatchOrphanCleanup(Media $media): bool
    {
        if ((string) ($media->collection_name ?? '') !== 'avatar') {
            return false;
        }

        $disk = (string) ($media->disk ?? '');
        if ($disk === '') {
            return false;
        }

        $driver = (string) config("filesystems.disks.{$disk}.driver", '');
        return in_array($driver, ['local', 'public'], true);
    }
}
