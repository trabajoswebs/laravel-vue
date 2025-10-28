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
     * @param object $event Evento despachado por Spatie.
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

        $this->scheduler->handleConversionEvent($media);
    }
}
