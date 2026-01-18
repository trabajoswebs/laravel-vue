<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Services;

use App\Application\Uploads\Media\Contracts\MediaCleanupScheduler as MediaCleanupSchedulerContract;
use App\Application\Uploads\Media\DTO\CleanupStatePayload;
use App\Domain\Uploads\Media\Contracts\MediaResource;
use App\Infrastructure\Uploads\Core\Adapters\SpatieMediaResource;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use App\Infrastructure\Uploads\Core\Models\MediaCleanupState;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Servicio encargado de orquestar la limpieza de artefactos asegurando que las conversiones hayan finalizado.
 *
 * Esta clase persiste banderas y payloads en una tabla durable para sobrevivir
 * reinicios de Redis o expiraciones de cache, lo que garantiza la continuidad del proceso
 * de limpieza de archivos temporales y artefactos generados por Spatie Media Library.
 */
final class MediaCleanupScheduler implements MediaCleanupSchedulerContract
{
    private const LOCK_DURATION_SECONDS = 10;
    private const LOCK_TIMEOUT_SECONDS = 5;
    private const LOCK_MAX_RETRIES = 3;
    private const DEFAULT_CHUNK_SIZE = 100;
    private const DEFAULT_STATE_TTL_HOURS = 48;
    /**
     * Marca un Media como pendiente de conversiones si corresponde.
     *
     * Si hay conversiones esperadas que aún no se han completado, registra el estado
     * para que la limpieza se posponga hasta que estén listas. Si no hay conversiones
     * pendientes o están todas completas, limpia el estado.
     *
     * @param Media $media Instancia del modelo Media a evaluar.
     * @param array<int,string> $expectedConversions Lista de nombres de conversiones esperadas.
     */
    public function flagPendingConversions(MediaResource $media, array $expectedConversions): void
    {
        $spatie = $this->unwrap($media);
        $mediaId = $this->mediaId($spatie);
        $conversions = $this->normalizeConversions($expectedConversions);
        $state = $this->findState($mediaId);

        // Si no hay conversiones esperadas, limpia el estado si existe
        if ($conversions === []) {
            if ($state !== null) {
                $this->clearState($state, 'clear_conversions');
            }
            return;
        }

        // Si las conversiones ya están completas, limpia el estado
        if ($this->conversionsCompleted($spatie, $conversions, false)) {
            if ($state !== null) {
                $this->clearState($state, 'conversions_completed');
            }
            return;
        }

        // Si no hay estado existente, crea uno nuevo
        if ($state === null) {
            $state = new MediaCleanupState([
                'media_id' => $mediaId,
            ]);
        }

        // Actualiza el estado con la información del modelo y conversiones pendientes
        $state->collection = $spatie->collection_name;
        $state->model_type = $spatie->model_type;
        $state->model_id = $spatie->model_id !== null ? (string) $spatie->model_id : null;
        $state->conversions = $conversions;
        $state->flagged_at = now();
        if (!$this->persistState($state)) {
            Log::warning(__('media.cleanup.state_persistence_failed'), [
                'media_id'    => $mediaId,
                'reason'      => 'flag_pending',
                'conversions' => $conversions,
            ]);
        }

        Log::debug(__('media.cleanup.pending_flagged'), [
            'media_id'       => $mediaId,
            'collection'     => $spatie->collection_name,
            'conversions'    => $conversions,
            'expected_count' => count($conversions),
        ]);
    }

    /**
     * Agenda la limpieza de artefactos. Si aún hay conversiones pendientes, se pospone.
     *
     * Evalúa el progreso de las conversiones y decide si puede ejecutar la limpieza
     * inmediatamente o debe posponerla hasta que se completen todas las conversiones esperadas.
     *
     * @param MediaResource $triggerMedia Instancia del modelo Media que desencadena la limpieza.
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts Array de artefactos a limpiar, organizados por disco.
     * @param array<int,string|int|null> $preserveMediaIds Lista de IDs de Media que no deben ser eliminados.
     * @param array<int,string> $conversions Lista de nombres de conversiones esperadas.
     */
    public function scheduleCleanup(
        MediaResource $triggerMedia,
        array $artifacts,
        array $preserveMediaIds,
        array $conversions = []
    ): void {
        if ($artifacts === []) {
            return; // Si no hay artefactos, no hay nada que limpiar
        }

        $spatie = $this->unwrap($triggerMedia);
        $triggerMediaId = $this->mediaId($spatie);
        $conversions = $this->normalizeConversions($conversions);
        $progress = $this->evaluateConversions($spatie, $conversions, false);
        $state = $this->findState($triggerMediaId);
        $hasPendingFlag = $state !== null && !empty($state->conversions ?? []);
        $origins = $this->extractOriginMediaIds($artifacts);
        $preserve = $this->normalizePreserveIds($preserveMediaIds);

        Log::info(__('media.cleanup.conversions_progress'), [
            'media_id'        => $triggerMediaId,
            'collection'      => $spatie->collection_name,
            'expected'        => $progress['expected'],
            'generated'       => $progress['generated'],
            'pending'         => $progress['pending'],
            'pending_ratio'   => $progress['ratio'],
            'trigger'         => 'schedule_cleanup',
            'has_pending_flag' => $hasPendingFlag,
        ]);

        // Si no hay conversiones pendientes y todas están completas, ejecuta la limpieza inmediatamente
        if ($this->shouldDispatchImmediately($hasPendingFlag, $progress)) {
            $this->dispatchCleanup($artifacts, $preserve, [
                'trigger_media_id' => $triggerMediaId,
                'origins'          => $origins,
                'reason'           => 'conversions_already_completed',
            ]);
            return;
        }

        // Fallback: si las conversions llevan demasiado tiempo pendientes, limpiar igualmente.
        $graceMinutes = (int) config('media.cleanup.pending_grace_minutes', 30);
        $graceMinutes = $graceMinutes > 0 ? $graceMinutes : 30;
        if ($this->isPendingStale($state, $graceMinutes)) {
            $this->clearState($state, 'stale_pending_cleanup');
            $this->dispatchCleanup($artifacts, $preserve, [
                'trigger_media_id' => $triggerMediaId,
                'origins'          => $origins,
                'reason'           => 'stale_pending_cleanup',
                'grace_minutes'    => $graceMinutes,
            ]);
            return;
        }

        // Almacena el payload para ejecutar la limpieza cuando se completen las conversiones
        $stored = $this->storePayload(
            $triggerMedia,
            $triggerMediaId,
            $artifacts,
            $preserve,
            $conversions,
            $origins,
            $progress
        );

        // Si no se pudo almacenar el payload, ejecuta la limpieza degradada
        if ($stored === false) {
            Log::notice(__('media.cleanup.degraded_dispatch'), [
                'media_id'      => $triggerMediaId,
                'reason'        => 'state_persistence_failed',
                'pending_flag'  => $hasPendingFlag,
                'pending_ratio' => $progress['ratio'],
            ]);

            $this->dispatchCleanup($artifacts, $preserve, [
                'trigger_media_id' => $triggerMediaId,
                'origins'          => $origins,
                'reason'           => 'degraded_state_persistence',
            ]);
        }
    }

    /**
     * Procesa eventos de conversiones completadas o fallidas.
     *
     * Cuando Spatie Media Library informa que una conversión ha terminado,
     * este método verifica si todas las conversiones esperadas están completas
     * y ejecuta la limpieza si es el caso.
     *
     * @param MediaResource $media Instancia informada por Spatie Media Library.
     */
    public function handleConversionEvent(MediaResource $media): void
    {
        $spatie = $this->unwrap($media);
        $mediaId = $this->mediaId($spatie);

        try {
            $lock = Cache::lock("media_event:{$mediaId}", self::LOCK_DURATION_SECONDS);
        } catch (\Throwable $exception) {
            Log::notice(__('media.cleanup.event_lock_unavailable'), [
                'media_id' => $mediaId,
                'error'    => $exception->getMessage(),
            ]);

            $this->processConversionEventWithoutLock($spatie);
            return;
        }

        try {
            $lock->block(self::LOCK_TIMEOUT_SECONDS, function () use ($spatie, $mediaId): void {
                $this->processConversionEventWithoutLock($spatie);
            });
        } catch (LockTimeoutException $exception) {
            Log::warning(__('media.cleanup.event_lock_timeout'), [
                'media_id' => $mediaId,
                'error'    => $exception->getMessage(),
            ]);
        } finally {
            $lock?->release();
        }
    }

    private function processConversionEventWithoutLock(Media $media): void
    {
        $mediaId = $this->mediaId($media);
        $state = $this->findState($mediaId);

        if ($state === null) {
            return;
        }

        $payload = $this->hydratePayload($state->payload ?? null);
        $conversions = $this->normalizeConversions(
            $payload?->conversions ?? ($state->conversions ?? [])
        );

        $progress = $this->evaluateConversions($media, $conversions, true);

        Log::info(__('media.cleanup.conversions_progress'), [
            'media_id'      => $mediaId,
            'collection'    => $media->collection_name,
            'expected'      => $progress['expected'],
            'generated'     => $progress['generated'],
            'pending'       => $progress['pending'],
            'pending_ratio' => $progress['ratio'],
            'trigger'       => 'conversion_event',
        ]);

        if (!$progress['complete']) {
            return;
        }

        DB::transaction(function () use ($state, $payload, $mediaId): void {
            $this->clearState($state, 'conversion_event_clear');

            if ($payload?->hasArtifacts()) {
                $this->dispatchCleanup(
                    $payload->artifacts,
                    $payload->preserve,
                    [
                        'trigger_media_id' => $mediaId,
                        'origins'          => $payload->origins,
                        'reason'           => 'conversion_event',
                    ]
                );
            }
        });
    }

    /**
     * Intenta ejecutar limpiezas diferidas cuando se requiera flush manual.
     *
     * Este método se utiliza para forzar la ejecución de limpiezas que estaban
     * pendientes por conversiones incompletas.
     *
     * @param string $mediaId ID del Media para el cual se debe ejecutar el flush.
     */
    public function flushExpired(string $mediaId): void
    {
        $state = $this->findState($mediaId);

        if ($state === null) {
            return; // Si no hay estado, no hay nada que hacer
        }

        $payload = $this->hydratePayload($state->payload ?? null);

        // Limpia el estado
        $this->clearState($state, 'flush_expired_clear');

        // Si hay artefactos en el payload, ejecuta la limpieza
        if ($payload?->hasArtifacts()) {
            $this->dispatchCleanup(
                $payload->artifacts,
                $payload->preserve,
                [
                    'trigger_media_id' => $mediaId,
                    'origins'          => $payload->origins,
                    'reason'           => 'flush_expired',
                ]
            );
        }
    }

    /**
     * Purga estados expirados y ejecuta limpiezas degradadas si es necesario.
     *
     * Elimina registros de estados que han expirado y ejecuta la limpieza
     * para esos estados si aún tienen payloads pendientes.
     *
     * @param int|null $maxAgeHours Horas máximas que puede permanecer un estado antes de considerarse expirado. Si es null, usa la configuración predeterminada.
     * @param int $chunkSize Cantidad de registros procesados por lote para evitar sobrecargar la memoria.
     * @return int Número de estados purgados.
     */
    public function purgeExpired(?int $maxAgeHours = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): int
    {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }

        // Determina las horas máximas de vida para los estados
        $hours = $maxAgeHours ?? (int) config('media.cleanup.state_ttl_hours', self::DEFAULT_STATE_TTL_HOURS);
        $hours = $hours > 0 ? $hours : self::DEFAULT_STATE_TTL_HOURS;
        $cutoff = now()->subHours($hours);
        $purged = 0;

        // Consulta y procesa los estados expirados en lotes
        MediaCleanupState::query()
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($sub) use ($cutoff) {
                    $sub->whereNotNull('flagged_at')
                        ->where('flagged_at', '<=', $cutoff);
                })->orWhere(function ($sub) use ($cutoff) {
                    $sub->whereNotNull('payload_queued_at')
                        ->where('payload_queued_at', '<=', $cutoff);
                });
            })
            ->orderBy('media_id')
            ->chunk($chunkSize, function ($states) use (&$purged) {
                foreach ($states as $state) {
                    DB::transaction(function () use ($state, &$purged) {
                        $this->flushExpired((string) $state->media_id);
                        $purged++;
                    });
                }
            });

        if ($purged > 0) {
            Log::info(__('media.cleanup.states_expired_purged'), [
                'count'  => $purged,
                'cutoff' => $cutoff->toIso8601String(),
                'hours'  => $hours,
            ]);
        }

        return $purged;
    }

    /**
     * Verifica si las conversiones esperadas ya fueron generadas.
     *
     * @param Media $media Instancia del modelo Media.
     * @param array<int,string> $expected Lista de nombres de conversiones esperadas.
     * @param bool $missingIsComplete Indica si una Media que no existe se considera como completada.
     * @return bool `true` si todas las conversiones están completas, `false` en caso contrario.
     */
    private function conversionsCompleted(Media $media, array $expected, bool $missingIsComplete): bool
    {
        return $this->evaluateConversions($media, $expected, $missingIsComplete)['complete'];
    }

    /**
     * Encola el job de limpieza con artefactos agregados.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts Array de artefactos a limpiar, organizados por disco.
     * @param array<int,string> $normalizedPreserveIds Lista normalizada de IDs de Media que no deben ser eliminados.
     * @param array $context Información adicional para el registro de logs.
     */
    private function dispatchCleanup(array $artifacts, array $normalizedPreserveIds, array $context = []): void
    {
        if ($artifacts === []) {
            return; // Si no hay artefactos, no hay nada que limpiar
        }

        // Despacha el job de limpieza en commit exitoso
        DB::afterCommit(function () use ($artifacts, $normalizedPreserveIds): void {
            CleanupMediaArtifactsJob::dispatch($artifacts, $normalizedPreserveIds);
        });

        Log::info(__('media.cleanup.dispatched'), array_merge([
            'disks'          => array_keys($artifacts),
            'preserve'       => $normalizedPreserveIds,
            'artifact_count' => array_sum(array_map('count', $artifacts)),
        ], $context));
    }

    /**
     * Persiste el payload de limpieza hasta que finalicen las conversiones.
     *
     * Almacena temporalmente la información necesaria para ejecutar la limpieza
     * cuando se completen todas las conversiones esperadas.
     *
     * @param Media $triggerMedia Instancia del modelo Media que desencadena la limpieza.
     * @param string $mediaId ID del Media.
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts Array de artefactos a limpiar.
     * @param array<int,string> $normalizedPreserveIds Lista normalizada de IDs de Media a preservar.
     * @param array<int,string> $conversions Lista de conversiones esperadas.
     * @param array<int,string> $origins IDs de Media originales asociados a los artefactos.
     * @param array{complete:bool,expected:int,generated:int,pending:int,ratio:float} $progress Métricas de progreso de conversiones.
     * @return bool `true` si se almacenó correctamente, `false` en caso contrario.
     */
    private function storePayload(
        MediaResource $triggerMedia,
        string $mediaId,
        array $artifacts,
        array $normalizedPreserveIds,
        array $conversions,
        array $origins,
        array $progress
    ): bool {
        $state = $this->findState($mediaId);

        // Si no hay estado existente, crea uno nuevo
        if ($state === null) {
            $state = new MediaCleanupState([
                'media_id' => $mediaId,
            ]);
        }

        $spatie = $this->unwrap($triggerMedia);

        // Actualiza el estado con la información del modelo y el payload de limpieza
        $state->collection ??= $spatie->collection_name;
        $state->model_type ??= $spatie->model_type;
        $state->model_id ??= $spatie->model_id !== null ? (string) $spatie->model_id : null;
        $state->conversions = $conversions !== [] ? $conversions : ($state->conversions ?? []);
        $payloadDto = CleanupStatePayload::make(
            $artifacts,
            $normalizedPreserveIds,
            $conversions,
            $origins,
        );
        $state->payload = $payloadDto->toArray();
        $state->payload_queued_at = $payloadDto->queuedAt;
        if (!$this->persistState($state)) {
            Log::warning(__('media.cleanup.state_persistence_failed'), [
                'media_id' => $mediaId,
                'reason'   => 'store_payload',
            ]);

            return false;
        }

        Log::info(__('media.cleanup.deferred'), [
            'media_id'      => $mediaId,
            'disks'         => array_keys($artifacts),
            'origins'       => $payloadDto->origins,
            'expected'      => $progress['expected'],
            'pending'       => $progress['pending'],
            'pending_ratio' => $progress['ratio'],
        ]);

        return true;
    }

    /**
     * Filtra y normaliza nombres de conversiones.
     *
     * @param array<int,string> $conversions Lista de nombres de conversiones a normalizar.
     * @return array<int,string> Lista de nombres de conversiones filtrados y normalizados.
     */
    private function normalizeConversions(array $conversions): array
    {
        // Filtra conversiones válidas (strings no vacíos) y elimina duplicados
        $filtered = array_filter($conversions, static fn($name) => is_string($name) && $name !== '');
        $unique = array_values(array_unique(array_map(static fn($name) => (string) $name, $filtered)));

        return $unique;
    }

    /**
     * Normaliza los IDs de Media a preservar.
     *
     * @param array<int,string|int|null> $ids Lista de IDs a normalizar.
     * @return array<int,string> Lista de IDs normalizados como strings.
     */
    private function normalizePreserveIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if ($id === null) {
                continue; // Salta IDs nulos
            }

            $stringId = trim((string) $id);
            if ($stringId === '') {
                continue; // Salta IDs vacíos
            }

            $normalized[] = $stringId;
        }

        return array_values(array_unique($normalized));
    }

    private function isPendingStale(?MediaCleanupState $state, int $graceMinutes): bool
    {
        if ($state === null) {
            return false;
        }

        $timestamp = $state->payload_queued_at ?? $state->flagged_at;
        if ($timestamp === null) {
            return false;
        }

        if (!$timestamp instanceof CarbonInterface) {
            try {
                $timestamp = \Carbon\Carbon::parse($timestamp);
            } catch (\Throwable) {
                return false;
            }
        }

        return $timestamp->lte(now()->subMinutes($graceMinutes));
    }

    /**
     * Obtiene el ID del modelo Media como string.
     *
     * @param Media $media Instancia del modelo Media.
     * @return string ID del modelo Media.
     */
    private function mediaId(Media $media): string
    {
        return (string) $media->getKey();
    }

    /**
     * Extrae los IDs de Media originales de los artefactos.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts Array de artefactos.
     * @return array<int,string> Lista de IDs de Media originales encontrados.
     */
    private function extractOriginMediaIds(array $artifacts): array
    {
        $origins = [];

        foreach ($artifacts as $entries) {
            if (!is_array($entries)) {
                continue; // Salta entradas no array
            }

            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['mediaId'])) {
                    continue; // Salta entradas sin mediaId
                }

                $id = (string) $entry['mediaId'];
                if ($id === '') {
                    continue; // Salta IDs vacíos
                }

                $origins[$id] = true; // Usa un array asociativo para evitar duplicados
            }
        }

        return array_keys($origins); // Devuelve solo las claves (IDs únicos)
    }

    private function hydratePayload(?array $payload): ?CleanupStatePayload
    {
        return CleanupStatePayload::fromArray($payload);
    }

    private function clearState(MediaCleanupState $state, string $reason): void
    {
        $state->conversions = [];
        $state->flagged_at = null;
        $state->payload = null;
        $state->payload_queued_at = null;

        if (!$this->saveOrDeleteState($state)) {
            Log::warning(__('media.cleanup.state_persistence_failed'), [
                'media_id' => $state->media_id,
                'reason'   => $reason,
            ]);
        }
    }

    /**
     * @param array{complete:bool,expected:int,generated:int,pending:int,ratio:float} $progress
     */
    private function shouldDispatchImmediately(bool $hasPendingFlag, array $progress): bool
    {
        return !$hasPendingFlag && $progress['complete'];
    }

    /**
     * Calcula el progreso de conversiones esperadas y devuelve métricas para alerting.
     *
     * @param Media $media Instancia del modelo Media.
     * @param array<int,string> $expected Lista de nombres de conversiones esperadas.
     * @param bool $missingIsComplete Indica si una Media que no existe se considera como completada.
     * @return array{complete:bool, expected:int, generated:int, pending:int, ratio:float} Métricas de progreso de conversiones.
     */
    private function evaluateConversions(Media $media, array $expected, bool $missingIsComplete): array
    {
        $expectedCount = count($expected);

        // Si no hay conversiones esperadas, están todas completas
        if ($expectedCount === 0) {
            return [
                'complete'  => true,
                'expected'  => 0,
                'generated' => 0,
                'pending'   => 0,
                'ratio'     => 0.0,
            ];
        }

        try {
            // Obtiene una instancia fresca del modelo Media para verificar conversiones
            $fresh = $media->exists ? $media->fresh() : Media::find($media->getKey());
        } catch (\Throwable $e) {
            Log::debug(__('media.cleanup.conversion_status_unavailable'), [
                'media_id' => $this->mediaId($media),
                'error'    => $e->getMessage(),
            ]);

            // Si no se puede obtener el modelo, asume que no hay conversiones generadas
            return [
                'complete'  => false,
                'expected'  => $expectedCount,
                'generated' => 0,
                'pending'   => $expectedCount,
                'ratio'     => 1.0,
            ];
        }

        if ($fresh === null) {
            // Si el modelo no existe, decide según $missingIsComplete
            $pending = $missingIsComplete ? 0 : $expectedCount;
            $generated = $expectedCount - $pending;

            return [
                'complete'  => $missingIsComplete,
                'expected'  => $expectedCount,
                'generated' => $generated,
                'pending'   => $pending,
                'ratio'     => $expectedCount > 0 ? $pending / $expectedCount : 0.0,
            ];
        }

        // Cuenta las conversiones que ya han sido generadas
        $generated = 0;
        foreach ($expected as $conversion) {
            if ($fresh->hasGeneratedConversion($conversion)) {
                $generated++;
            }
        }

        // Asegura que los conteos no excedan el total esperado
        $generated = min($generated, $expectedCount);
        $pending = max(0, $expectedCount - $generated);

        return [
            'complete'  => $pending === 0,
            'expected'  => $expectedCount,
            'generated' => $generated,
            'pending'   => $pending,
            'ratio'     => $expectedCount > 0 ? $pending / $expectedCount : 0.0,
        ];
    }

    /**
     * Busca el estado de limpieza para un Media específico.
     *
     * @param string $mediaId ID del Media.
     * @return MediaCleanupState|null Estado encontrado o null si no existe.
     */
    private function findState(string $mediaId): ?MediaCleanupState
    {
        try {
            return MediaCleanupState::query()->find($mediaId);
        } catch (\Throwable $e) {
            Log::warning(__('media.cleanup.state_unavailable'), [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Guarda o elimina el estado dependiendo de si está vacío.
     *
     * @param MediaCleanupState $state Estado a guardar o eliminar.
     * @return bool `true` si la operación fue exitosa, `false` en caso contrario.
     */
    private function saveOrDeleteState(MediaCleanupState $state): bool
    {
        if ($this->stateIsEmpty($state)) {
            if ($state->exists) {
                return $this->deleteState($state);
            }

            return true; // Si no existe y está vacío, no hay nada que hacer
        }

        return $this->persistState($state);
    }

    /**
     * Verifica si un estado está vacío (sin conversiones ni payload).
     *
     * @param MediaCleanupState $state Estado a verificar.
     * @return bool `true` si el estado está vacío, `false` en caso contrario.
     */
    private function stateIsEmpty(MediaCleanupState $state): bool
    {
        $conversions = $state->conversions ?? [];
        $payload = $state->payload ?? [];

        return empty($conversions) && empty($payload);
    }

    /**
     * Persiste el estado en la base de datos con control de concurrencia.
     *
     * @param MediaCleanupState $state Estado a persistir.
     * @return bool `true` si la operación fue exitosa, `false` en caso contrario.
     */
    private function persistState(MediaCleanupState $state): bool
    {
        $save = function () use ($state): bool {
            try {
                $state->save();
                return true;
            } catch (\Throwable $e) {
                Log::warning(__('media.cleanup.state_save_failed'), [
                    'media_id' => $state->media_id,
                    'error'    => $e->getMessage(),
                ]);

                return false;
            }
        };

        $attempt = 0;

        while ($attempt < self::LOCK_MAX_RETRIES) {
            ++$attempt;

            try {
                $lock = Cache::lock("media_state:{$state->media_id}", self::LOCK_DURATION_SECONDS);
            } catch (\Throwable $lockError) {
                Log::notice(__('media.cleanup.state_lock_unavailable'), [
                    'media_id' => $state->media_id,
                    'error'    => $lockError->getMessage(),
                ]);

                return $save();
            }

            try {
                $result = $lock->block(self::LOCK_TIMEOUT_SECONDS, $save);
                if ($result) {
                    return true;
                }
            } catch (LockTimeoutException $exception) {
                Log::warning(__('media.cleanup.state_lock_timeout'), [
                    'media_id' => $state->media_id,
                    'attempt'  => $attempt,
                    'error'    => $exception->getMessage(),
                ]);
                usleep(100000 * $attempt);
            } finally {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Ignorado
                }
            }
        }

        Log::notice(__('media.cleanup.state_save_without_lock'), [
            'media_id' => $state->media_id,
            'attempts' => self::LOCK_MAX_RETRIES,
        ]);

        return $save();
    }

    /**
     * Elimina un estado de la base de datos.
     *
     * @param MediaCleanupState $state Estado a eliminar.
     * @return bool `true` si la operación fue exitosa, `false` en caso contrario.
     */
    private function deleteState(MediaCleanupState $state): bool
    {
        try {
            $state->delete();
            return true;
        } catch (\Throwable $e) {
            Log::warning(__('media.cleanup.state_delete_failed'), [
                'media_id' => $state->media_id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function unwrap(MediaResource $resource): Media
    {
        if ($resource instanceof SpatieMediaResource) {
            $raw = $resource->raw();
            if ($raw instanceof Media) {
                return $raw;
            }
        }

        $raw = $resource->raw();
        if ($raw instanceof Media) {
            return $raw;
        }

        throw new \InvalidArgumentException('Unsupported media resource for cleanup scheduler');
    }
}
