<?php

declare(strict_types=1);

namespace App\Listeners\Media;

use App\Support\Media\Services\MediaCleanupScheduler;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Dispara la limpieza diferida de artefactos cuando Spatie finaliza las conversions.
 */
final class RunPendingMediaCleanup
{
    public function __construct(
        private readonly MediaCleanupScheduler $scheduler,
    ) {}

    /**
     * Maneja eventos ConversionHasBeenCompleted/Failed.
     *
     * Contrato del evento (Spatie v10/v11):
     *  - Propiedad pública `media`: instancia de {@see Media}.
     *  - Propiedad opcional `conversionName`: string|null.
     *
     * @param object $event Evento despachado por Spatie (conversion completada o fallida).
     */
    public function handle(object $event): void
    {
        $media = property_exists($event, 'media') ? $event->media : null;

        if (!$media instanceof Media || !$media->id) {
            Log::debug('media_cleanup.listener_missing_media', [
                'event_class' => is_object($event) ? $event::class : gettype($event),
            ]);
            return;
        }

        $disk = $media->disk ?? config('filesystems.default');

        if (!$this->isAllowedDisk($disk)) {
            Log::warning('ppam_listener_invalid_disk', [
                'media_id' => $media->id,
                'disk' => $disk,
                'conversion_fired' => property_exists($event, 'conversionName') ? $event->conversionName : null,
                'metric' => 'media.cleanup.invalid_disk',
            ]);
            return;
        }

        $this->scheduler->handleConversionEvent($media);
    }

    /**
     * @return array<int,string>
     */
    private function allowedDisks(): array
    {
        $cfg = config('media.allowed_disks');

        if (is_array($cfg) && $cfg !== []) {
            return array_values(array_map('strval', $cfg));
        }

        return array_keys((array) config('filesystems.disks', []));
    }

    private function isAllowedDisk(?string $disk): bool
    {
        if ($disk === null || $disk === '') {
            return true;
        }

        return in_array($disk, $this->allowedDisks(), true);
    }
}
