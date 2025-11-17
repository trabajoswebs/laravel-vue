<?php

declare(strict_types=1);

namespace App\Support\Media\Services;

use App\Jobs\CleanupMediaArtifactsJob;
use App\Support\Media\DTO\CleanupStatePayload;
use App\Support\Media\Models\MediaCleanupState;
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
final class MediaCleanupScheduler
{
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
    public function flagPendingConversions(Media $media, array $expectedConversions): void
    {
        $mediaId = $this->mediaId($media);
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
        if ($this->conversionsCompleted($media, $conversions, false)) {
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
        $state->collection = $media->collection_name;
        $state->model_type = $media->model_type;
        $state->model_id = $media->model_id !== null ? (string) $media->model_id : null;
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
            'collection'     => $media->collection_name,
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
     * @param Media $triggerMedia Instancia del modelo Media que desencadena la limpieza.
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts Array de artefactos a limpiar, organizados por disco.
     * @param array<int,string|int|null> $preserveMediaIds Lista de IDs de Media que no deben ser eliminados.
     * @param array<int,string> $expectedConversions Lista de nombres de conversiones esperadas.
     */
    public function scheduleCleanup(
        Media $triggerMedia,
        array $artifacts,
        array $preserveMediaIds,
        array $expectedConversions
    ): void {
        if ($artifacts === []) {
            return; // Si no hay artefactos, no hay nada que limpiar
        }

        $triggerMediaId = $this->mediaId($triggerMedia);
        $conversions = $this->normalizeConversions($expectedConversions);
        $progress = $this->evaluateConversions($triggerMedia, $conversions, false);
        $state = $this->findState($triggerMediaId);
        $hasPendingFlag = $state !== null && !empty($state->conversions ?? []);
        $origins = $this->extractOriginMediaIds($artifacts);
        $preserve = $this->normalizePreserveIds($preserveMediaIds);

        Log::info(__('media.cleanup.conversions_progress'), [
            'media_id'        => $triggerMediaId,
            'collection'      => $triggerMedia->collection_name,
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
     * @param Media $media Instancia informada por Spatie Media Library.
     */
    public function handleConversionEvent(Media $media): void
    {
        $mediaId = $this->mediaId($media);
        $state = $this->findState($mediaId);

        if ($state === null) {
            return; // Si no hay estado registrado, no hay nada que hacer
        }

        // Obtiene el payload almacenado con la información de limpieza
        $payload = $this->hydratePayload($state->payload ?? null);
        $conversions = $this->normalizeConversions(
            $payload?->conversions ?? ($state->conversions ?? [])
        );

        // Evalúa el progreso actual de las conversiones
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

        // Si no todas las conversiones están completas, no ejecuta la limpieza
        if (!$progress['complete']) {
            return;
        }

        // Limpia el estado de conversiones pendientes
        $this->clearState($state, 'conversion_event_clear');

        // Si hay artefactos almacenados en el payload, ejecuta la limpieza
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
    public function purgeExpired(?int $maxAgeHours = null, int $chunkSize = 100): int
    {
        // Determina las horas máximas de vida para los estados
        $hours = $maxAgeHours ?? (int) config('media.cleanup.state_ttl_hours', 48);
        $hours = $hours > 0 ? $hours : 48;
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
                    $this->flushExpired((string) $state->media_id);
                    $purged++;
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
        Media $triggerMedia,
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

        // Actualiza el estado con la información del modelo y el payload de limpieza
        $state->collection ??= $triggerMedia->collection_name;
        $state->model_type ??= $triggerMedia->model_type;
        $state->model_id ??= $triggerMedia->model_id !== null ? (string) $triggerMedia->model_id : null;
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
        $lock = null;
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

        try {
            // Intenta obtener un lock para evitar conflictos de concurrencia
            $lock = Cache::lock("media_state:{$state->media_id}", 10);
        } catch (\Throwable $lockError) {
            Log::notice(__('media.cleanup.state_lock_unavailable'), [
                'media_id' => $state->media_id,
                'error'    => $lockError->getMessage(),
            ]);

            return $save(); // Si no se puede obtener el lock, guarda directamente
        }

        try {
            // Bloquea la operación por un máximo de 5 segundos
            return $lock->block(5, $save);
        } catch (LockTimeoutException $exception) {
            Log::warning(__('media.cleanup.state_lock_timeout'), [
                'media_id' => $state->media_id,
                'error'    => $exception->getMessage(),
            ]);
            return false;
        } finally {
            // Asegura que el lock se libere
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Ignora errores al liberar el lock si ya no pertenece al proceso actual
                }
            }
        }
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
}
