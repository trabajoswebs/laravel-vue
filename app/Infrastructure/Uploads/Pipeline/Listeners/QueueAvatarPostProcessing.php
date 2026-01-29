<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Listeners;

use App\Application\Shared\Contracts\ClockInterface;
use App\Application\Shared\Contracts\LoggerInterface;
use App\Infrastructure\Uploads\Pipeline\Jobs\PostProcessAvatarMedia; // Job de post-proceso; ej. optimiza media
use App\Infrastructure\Uploads\Pipeline\Jobs\ProcessLatestAvatar; // Job coalescedor por usuario
use Illuminate\Support\Facades\Redis; // Redis para lock/coalescing por usuario
use Illuminate\Support\Str; // Generar UUIDs; ej. Str::uuid()
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media; ej. Media #5

/**
 * Listener síncrono que encola el job de post-procesado/optimización
 * cuando Spatie Media Library termina una conversión de avatar u otras colecciones configuradas.
 *
 * Características:
 * - No implementa ShouldQueue para reducir overhead y puntos de fallo.
 * - Implementa debounce con Cache::add() para evitar múltiples dispatchs rápidos (1 job por media cada 30s).
 * - Utiliza configuración centralizada vía config/image-pipeline.php (colecciones y conversiones).
 * - Incluye Correlation ID para trazabilidad end-to-end con el Job.
 *
 * Nota: No se tipa el evento como ConversionHasBeenCompleted para evitar
 * errores de IDE como "Undefined type"; se usa object + instanceof.
 *
 * Listener que encola post-proceso/optimización tras conversions de ML.
 * Ahora soporta múltiples colecciones configurables, manteniendo compatibilidad
 * con el flujo existente de avatar.
 */
final class QueueAvatarPostProcessing
{
    /**
     * Colecciones que requieren postprocesamiento (por ejemplo, ['avatar','gallery']).
     *
     * @var array<int,string>
     */
    private array $collections;
    
    /**
     * Conversiones que se deben procesar para las colecciones configuradas.
     *
     * @var array<int,string>
     */
    private array $conversions;

    /**
     * Constructor del listener.
     *
     * Inicializa las colecciones y conversiones desde la configuración del sistema.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
        // Resuelve y almacena las colecciones y conversiones desde la configuración
        $this->collections = $this->resolveCollectionsFromConfig();
        $this->conversions = $this->resolveConversionsFromConfig();
    }

    /**
     * Resuelve las colecciones a postprocesar desde la configuración.
     *
     * Lee la configuración de 'image-pipeline.postprocess_collections' y la convierte
     * en un array de nombres de colecciones. Si no está configurado, usa la colección
     * de avatar por defecto.
     *
     * @return array<int,string> Array de nombres de colecciones a postprocesar.
     */
    private function resolveCollectionsFromConfig(): array
    {
        // Obtiene la configuración de colecciones a postprocesar
        $configured = config('image-pipeline.postprocess_collections');

        // Procesa la configuración dependiendo de su tipo
        if (is_string($configured)) {
            // Si es una cadena, divídela por comas o espacios
            $list = preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($configured)) {
            // Si es un array, filtra para mantener solo cadenas no vacías
            $list = array_values(array_filter($configured, static fn ($value) => is_string($value) && $value !== ''));
        } else {
            // Si no es ni string ni array, inicializa como array vacío
            $list = [];
        }

        // Si no se configuraron colecciones, usa la colección de avatar por defecto
        if ($list === []) {
            $list = [(string) config('image-pipeline.avatar_collection', 'avatar')];
        }

        // Elimina duplicados y reindexa el array
        return array_values(array_unique($list));
    }

    /**
     * Resuelve las conversiones a postprocesar desde la configuración.
     *
     * Lee la configuración de 'image-pipeline.avatar_sizes' y extrae los nombres
     * de las conversiones. Si no se encuentran conversiones válidas, usa valores por defecto.
     *
     * @return array<int,string> Array de nombres de conversiones a postprocesar.
     */
    private function resolveConversionsFromConfig(): array
    {
        // Obtiene la configuración de tamaños de avatar
        $sizes = config('image-pipeline.avatar_sizes', [
            'thumb'  => 128,
            'medium' => 256,
            'large'  => 512,
        ]);

        // Extrae los nombres de las conversiones (claves del array)
        $conversions = array_values(
            array_filter(
                array_keys(is_array($sizes) ? $sizes : []),
                static fn ($key) => is_string($key) && $key !== '' // Filtra claves válidas
            )
        );

        // Si no se encontraron conversiones válidas, usa valores por defecto
        if ($conversions === []) {
            $conversions = ['thumb', 'medium', 'large'];
        }

        return $conversions;
    }

    /**
     * Maneja el evento de conversión completada.
     *
     * Este método implementa el contrato del evento de Spatie Media Library (versiones 10 y 11):
     *  - Propiedad pública `media`: instancia de {@see Media}.
     *  - Propiedad pública `conversionName`: string|null con el nombre de la conversión.
     *
     * @param object $event Evento disparado tras completar una conversión por Spatie Media Library.
     */
    public function handle(object $event): void
    {
        // 1) Extrae el payload del evento de forma segura (sin depender del tipo exacto del evento)
        $media = property_exists($event, 'media') ? $event->media : null;
        $conversionName = property_exists($event, 'conversionName') ? $event->conversionName : null;

        // Verifica que el objeto Media sea válido y tenga ID
        if (!$media instanceof Media || !$media->id) {
            // Registra un warning si no se encuentra una instancia válida de Media
            $this->logger->warning('ppam_listener_missing_media', [
                'media_type'       => is_object($media) ? get_class($media) : gettype($media),
                'conversion_fired' => $conversionName,
            ]);
            return; // Sale si no hay Media válida
        }

        // 2) Filtra por colecciones esperadas (definidas en la configuración)
        $collection = (string) $media->collection_name;
        if (!in_array($collection, $this->collections, true)) {
            // Registra un debug si la colección no está en la lista permitida
            $this->logger->debug('ppam_listener_skip_collection', [
                'media_id'         => $media->id,
                'collection'       => $collection,
                'expected'         => $this->collections,
                'conversion_fired' => $conversionName,
            ]);
            return; // Sale si la colección no está permitida
        }

        // 3) Genera un Correlation ID para trazabilidad entre el listener y el job
        $correlationId = (string) Str::uuid();

        $tenantId = $media->getCustomProperty('tenant_id') ?? tenant()?->getKey(); // Resuelve tenant desde custom_props o helper; ej. 3
        if ($tenantId === null) { // Sin tenant -> aborta
            $this->logger->warning('ppam_listener_missing_tenant', [ // Log warning para trazabilidad
                'media_id' => $media->id, // Ej. 5
                'collection' => $collection, // Ej. avatar
                'conversion_fired' => $conversionName, // Ej. thumb
            ]);
            return; // No encola job sin tenant
        }

        // 4) Persistir SIEMPRE el último media para el usuario/tenant
        ProcessLatestAvatar::rememberLatest(
            $tenantId,
            $media->model_id,
            $media->getKey(),
            $media->getCustomProperty('upload_uuid') ?? (string) $media->uuid,
            $media->getCustomProperty('correlation_id') ?? $correlationId
        );

        // 5) Intentar adquirir el lock por (tenant,user); si se obtiene, encola el job.
        $lockKey = sprintf('ppam:avatar:lock:%s:%s', $tenantId, $media->model_id);
        try {
            $acquired = Redis::set($lockKey, '1', 'EX', 60, 'NX');
        } catch (\Throwable $e) {
            $this->logger->warning('ppam_listener_cache_unavailable', [
                'media_id'         => $media->id,
                'user_id'          => $media->model_id,
                'tenant_id'        => $tenantId,
                'error'            => $e->getMessage(),
                'degraded'         => true,
                'conversion_fired' => $conversionName,
            ]);
            $acquired = true; // degradar a dispatch para no perder el procesamiento
        }

        if ($acquired) {
            ProcessLatestAvatar::dispatch($tenantId, $media->model_id);
        } else {
            $this->logger->debug('ppam_listener_debounced', [
                'media_id'         => $media->id,
                'user_id'          => $media->model_id,
                'tenant_id'        => $tenantId,
                'key'              => $lockKey,
                'ttl_seconds'      => 60,
                'conversion_fired' => $conversionName,
            ]);
            $this->logger->info('ppam_listener_debounced_metric', [
                'media_id'    => $media->id,
                'user_id'     => $media->model_id,
                'tenant_id'   => $tenantId,
                'key'         => $lockKey,
                'ttl_seconds' => 60,
                'metric'      => 'ppam.dispatch.debounced',
            ]);
        }

        $this->logger->info('ppam_listener_dispatch', [
            'media_id'         => $media->id,
            'collection'       => $collection,
            'conversions'      => $this->conversions,
            'conversion_fired' => $conversionName,
            'correlation_id'   => $correlationId,
            'tenant_id'        => $tenantId,
            'user_id'          => $media->model_id,
            'latest_key'       => sprintf('ppam:avatar:last:%s:%s', $tenantId, $media->model_id),
            'lock_key'         => $lockKey,
            'coalesced'        => true,
        ]);
    }
}
