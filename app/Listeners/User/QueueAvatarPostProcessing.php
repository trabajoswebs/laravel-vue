<?php

declare(strict_types=1);

namespace App\Listeners\User;

use App\Jobs\PostProcessAvatarMedia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Listener síncrono: encola el job de post-procesado/optimización
 * cuando Spatie ML termina una conversión de AVATAR.
 *
 * - Sin ShouldQueue (menos overhead y puntos de fallo).
 * - Debounce con Cache::add() para evitar dispatch-storm (1 job por media/30s).
 * - Config centralizada vía config/image-pipeline.php (colección + conversions).
 * - Correlation ID para trazabilidad end-to-end con el Job.
 *
 * Nota: No tipamos el evento a ConversionHasBeenCompleted para evitar
 * el error de Intelephense “Undefined type …”; usamos object + instanceof.
 */
final class QueueAvatarPostProcessing
{
    private string $collection;
    /** @var array<int,string> */
    private array $conversions;

    public function __construct()
    {
        // Colección desde config (fallback 'avatar')
        $this->collection = (string) config('image-pipeline.avatar_collection', 'avatar');

        // Conversions = keys de avatar_sizes (fallback a defaults)
        $sizes = config('image-pipeline.avatar_sizes', [
            'thumb'  => 128,
            'medium' => 256,
            'large'  => 512,
        ]);

        $this->conversions = array_values(
            array_filter(
                array_keys(is_array($sizes) ? $sizes : []),
                static fn ($k) => is_string($k) && $k !== ''
            )
        );

        if (empty($this->conversions)) {
            $this->conversions = ['thumb', 'medium', 'large'];
        }
    }

    /**
     * Maneja el evento de conversión completada.
     *
     * @param object $event Evento disparado tras completar una conversión.
     */
    public function handle(object $event): void
    {
        // 1) Extraer payload de forma segura (sin depender del tipo exacto del evento)
        $media = property_exists($event, 'media') ? $event->media : null;
        $conversionName = property_exists($event, 'conversionName') ? $event->conversionName : null;

        if (!$media instanceof Media || !$media->id) {
            Log::warning('ppam_listener_missing_media', [
                'media_type'       => is_object($media) ? get_class($media) : gettype($media),
                'conversion_fired' => $conversionName,
            ]);
            return;
        }

        // 2) Filtrar por colección esperada (desde config)
        if ((string) $media->collection_name !== $this->collection) {
            Log::debug('ppam_listener_skip_collection', [
                'media_id'         => $media->id,
                'collection'       => (string) $media->collection_name,
                'expected'         => $this->collection,
                'conversion_fired' => $conversionName,
            ]);
            return;
        }

        // 3) Debounce: 1 dispatch por media en ventana (30s)
        $key = "ppam:dispatch:media:{$media->id}";
        $ttl = now()->addSeconds(30);

        try {
            if (!Cache::add($key, '1', $ttl)) {
                Log::debug('ppam_listener_debounced', [
                    'media_id'         => $media->id,
                    'key'              => $key,
                    'ttl_seconds'      => 30,
                    'conversion_fired' => $conversionName,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            // Fallback degradado: si la caché está caída, seguimos para no perder el post-proceso
            Log::warning('ppam_listener_cache_unavailable', [
                'media_id'         => $media->id,
                'error'            => $e->getMessage(),
                'degraded'         => true,
                'conversion_fired' => $conversionName,
            ]);
        }

        // 4) Correlation ID para trazabilidad con el Job
        $correlationId = (string) Str::uuid();

        // 5) Despacho del Job endurecido (no bloqueante, cola image-optimization)
        try {
            PostProcessAvatarMedia::dispatchFor(
                media: $media,
                conversions: $this->conversions,
                collection: $this->collection,
                correlationId: $correlationId
            );

            Log::info('ppam_listener_dispatch', [
                'media_id'         => $media->id,
                'collection'       => $this->collection,
                'conversions'      => $this->conversions,
                'conversion_fired' => $conversionName,
                'correlation_id'   => $correlationId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ppam_listener_dispatch_failed', [
                'media_id'         => $media->id,
                'collection'       => $this->collection,
                'conversions'      => $this->conversions,
                'conversion_fired' => $conversionName,
                'error'            => $e->getMessage(),
                'correlation_id'   => $correlationId,
            ]);
        }
    }
}
