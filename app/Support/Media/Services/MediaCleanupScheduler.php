<?php

declare(strict_types=1);

namespace App\Support\Media\Services;

use App\Jobs\CleanupMediaArtifactsJob;
use App\Support\Media\Models\MediaCleanupState;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Orquesta la limpieza de artefactos asegurando que las conversions hayan finalizado.
 *
 * Ahora persiste banderas y payloads en una tabla durable para sobrevivir reinicios
 * de Redis o expiraciones de cache.
 */
final class MediaCleanupScheduler
{
    /**
     * Marca un Media como pendiente de conversions si corresponde.
     *
     * @param array<int,string> $expectedConversions
     */
    public function flagPendingConversions(Media $media, array $expectedConversions): void
    {
        $mediaId = $this->mediaId($media);
        $conversions = $this->normalizeConversions($expectedConversions);
        $state = $this->findState($mediaId);

        if ($conversions === []) {
            if ($state !== null) {
                $state->conversions = [];
                $state->flagged_at = null;
                if (!$this->saveOrDeleteState($state)) {
                    Log::warning('media_cleanup.state_persistence_failed', [
                        'media_id' => $mediaId,
                        'reason'   => 'clear_conversions',
                    ]);
                }
            }
            return;
        }

        if ($this->conversionsCompleted($media, $conversions, false)) {
            if ($state !== null) {
                $state->conversions = [];
                $state->flagged_at = null;
                if (!$this->saveOrDeleteState($state)) {
                    Log::warning('media_cleanup.state_persistence_failed', [
                        'media_id' => $mediaId,
                        'reason'   => 'conversions_completed',
                    ]);
                }
            }
            return;
        }

        if ($state === null) {
            $state = new MediaCleanupState([
                'media_id' => $mediaId,
            ]);
        }

        $state->collection = $media->collection_name;
        $state->model_type = $media->model_type;
        $state->model_id = $media->model_id !== null ? (string) $media->model_id : null;
        $state->conversions = $conversions;
        $state->flagged_at = now();
        if (!$this->persistState($state)) {
            Log::warning('media_cleanup.state_persistence_failed', [
                'media_id'    => $mediaId,
                'reason'      => 'flag_pending',
                'conversions' => $conversions,
            ]);
        }

        Log::debug('media_cleanup.pending_flagged', [
            'media_id'       => $mediaId,
            'collection'     => $media->collection_name,
            'conversions'    => $conversions,
            'expected_count' => count($conversions),
        ]);
    }

    /**
     * Agenda la limpieza de artefactos. Si aún hay conversions pendientes, se pospone.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string|int|null> $preserveMediaIds
     * @param array<int,string> $expectedConversions
     */
    public function scheduleCleanup(
        Media $triggerMedia,
        array $artifacts,
        array $preserveMediaIds,
        array $expectedConversions
    ): void {
        if ($artifacts === []) {
            return;
        }

        $triggerMediaId = $this->mediaId($triggerMedia);
        $conversions = $this->normalizeConversions($expectedConversions);
        $progress = $this->evaluateConversions($triggerMedia, $conversions, false);
        $state = $this->findState($triggerMediaId);
        $hasPendingFlag = $state !== null && !empty($state->conversions ?? []);
        $origins = $this->extractOriginMediaIds($artifacts);
        $preserve = $this->normalizePreserveIds($preserveMediaIds);

        Log::info('media_cleanup.conversions_progress', [
            'media_id'        => $triggerMediaId,
            'collection'      => $triggerMedia->collection_name,
            'expected'        => $progress['expected'],
            'generated'       => $progress['generated'],
            'pending'         => $progress['pending'],
            'pending_ratio'   => $progress['ratio'],
            'trigger'         => 'schedule_cleanup',
            'has_pending_flag' => $hasPendingFlag,
        ]);

        if (!$hasPendingFlag && $progress['complete']) {
            $this->dispatchCleanup($artifacts, $preserve, [
                'trigger_media_id' => $triggerMediaId,
                'origins'          => $origins,
                'reason'           => 'conversions_already_completed',
            ]);
            return;
        }

        $stored = $this->storePayload(
            $triggerMedia,
            $triggerMediaId,
            $artifacts,
            $preserve,
            $conversions,
            $origins,
            $progress
        );

        if ($stored === false) {
            Log::notice('media_cleanup.degraded_dispatch', [
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
     * Procesa eventos de conversions completadas o fallidas.
     *
     * @param Media $media Instancia informada por Spatie.
     */
    public function handleConversionEvent(Media $media): void
    {
        $mediaId = $this->mediaId($media);
        $state = $this->findState($mediaId);

        if ($state === null) {
            return;
        }

        $payload = $state->payload ?? null;
        $conversions = $this->normalizeConversions(
            $payload['conversions'] ?? ($state->conversions ?? [])
        );

        $progress = $this->evaluateConversions($media, $conversions, true);

        Log::info('media_cleanup.conversions_progress', [
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

        $state->conversions = [];
        $state->flagged_at = null;
        $state->payload = null;
        $state->payload_queued_at = null;
        if (!$this->saveOrDeleteState($state)) {
            Log::warning('media_cleanup.state_persistence_failed', [
                'media_id' => $mediaId,
                'reason'   => 'conversion_event_clear',
            ]);
        }

        if (is_array($payload) && !empty($payload['artifacts'] ?? [])) {
            $this->dispatchCleanup(
                $payload['artifacts'],
                $payload['preserve'] ?? [],
                [
                    'trigger_media_id' => $mediaId,
                    'origins'          => $payload['origins'] ?? [],
                    'reason'           => 'conversion_event',
                ]
            );
        }
    }

    /**
     * Intenta ejecutar limpiezas diferidas cuando se requiera flush manual.
     *
     * @param string $mediaId
     */
    public function flushExpired(string $mediaId): void
    {
        $state = $this->findState($mediaId);

        if ($state === null) {
            return;
        }

        $payload = $state->payload ?? null;

        $state->conversions = [];
        $state->flagged_at = null;
        $state->payload = null;
        $state->payload_queued_at = null;
        if (!$this->saveOrDeleteState($state)) {
            Log::warning('media_cleanup.state_persistence_failed', [
                'media_id' => $mediaId,
                'reason'   => 'flush_expired_clear',
            ]);
        }

        if (is_array($payload) && !empty($payload['artifacts'] ?? [])) {
            $this->dispatchCleanup(
                $payload['artifacts'],
                $payload['preserve'] ?? [],
                [
                    'trigger_media_id' => $mediaId,
                    'origins'          => $payload['origins'] ?? [],
                    'reason'           => 'flush_expired',
                ]
            );
        }
    }

    /**
     * ¿Las conversions esperadas ya fueron generadas?
     *
     * @param Media $media
     * @param array<int,string> $expected
     */
    private function conversionsCompleted(Media $media, array $expected, bool $missingIsComplete): bool
    {
        return $this->evaluateConversions($media, $expected, $missingIsComplete)['complete'];
    }

    /**
     * Encola el job de limpieza con artefactos agregados.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $normalizedPreserveIds
     */
    private function dispatchCleanup(array $artifacts, array $normalizedPreserveIds, array $context = []): void
    {
        if ($artifacts === []) {
            return;
        }

        CleanupMediaArtifactsJob::dispatch($artifacts, $normalizedPreserveIds);

        Log::info('media_cleanup.dispatched', array_merge([
            'disks'          => array_keys($artifacts),
            'preserve'       => $normalizedPreserveIds,
            'artifact_count' => array_sum(array_map('count', $artifacts)),
        ], $context));
    }

    /**
     * Persist payload de cleanup hasta que finalicen las conversions.
     *
     * @param string $mediaId
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $normalizedPreserveIds
     * @param array<int,string> $conversions
     * @param array<int,string> $origins
     * @param array{complete:bool,expected:int,generated:int,pending:int,ratio:float} $progress
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

        if ($state === null) {
            $state = new MediaCleanupState([
                'media_id' => $mediaId,
            ]);
        }

        $state->collection ??= $triggerMedia->collection_name;
        $state->model_type ??= $triggerMedia->model_type;
        $state->model_id ??= $triggerMedia->model_id !== null ? (string) $triggerMedia->model_id : null;
        $state->conversions = $conversions !== [] ? $conversions : ($state->conversions ?? []);
        $state->payload = [
            'artifacts'   => $artifacts,
            'preserve'    => $normalizedPreserveIds,
            'conversions' => $conversions,
            'queued_at'   => now()->toIso8601String(),
            'origins'     => $origins,
        ];
        $state->payload_queued_at = now();
        if (!$this->persistState($state)) {
            Log::warning('media_cleanup.state_persistence_failed', [
                'media_id' => $mediaId,
                'reason'   => 'store_payload',
            ]);

            return false;
        }

        Log::info('media_cleanup.deferred', [
            'media_id'      => $mediaId,
            'disks'         => array_keys($artifacts),
            'origins'       => $origins,
            'expected'      => $progress['expected'],
            'pending'       => $progress['pending'],
            'pending_ratio' => $progress['ratio'],
        ]);

        return true;
    }

    /**
     * Filtra y normaliza nombres de conversions.
     *
     * @param array<int,string> $conversions
     * @return array<int,string>
     */
    private function normalizeConversions(array $conversions): array
    {
        $filtered = array_filter($conversions, static fn($name) => is_string($name) && $name !== '');
        $unique = array_values(array_unique(array_map(static fn($name) => (string) $name, $filtered)));

        return $unique;
    }

    /**
     * @param array<int,string|int|null> $ids
     * @return array<int,string>
     */
    private function normalizePreserveIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }

            $stringId = trim((string) $id);
            if ($stringId === '') {
                continue;
            }

            $normalized[] = $stringId;
        }

        return array_values(array_unique($normalized));
    }

    private function mediaId(Media $media): string
    {
        return (string) $media->getKey();
    }

    /**
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @return array<int,string>
     */
    private function extractOriginMediaIds(array $artifacts): array
    {
        $origins = [];

        foreach ($artifacts as $entries) {
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_array($entry) || !isset($entry['mediaId'])) {
                    continue;
                }

                $id = (string) $entry['mediaId'];
                if ($id === '') {
                    continue;
                }

                $origins[$id] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * Calcula el progreso de conversions esperadas y devuelve métricas para alerting.
     *
     * @param array<int,string> $expected
     * @return array{complete:bool, expected:int, generated:int, pending:int, ratio:float}
     */
    private function evaluateConversions(Media $media, array $expected, bool $missingIsComplete): array
    {
        $expectedCount = count($expected);

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
            $fresh = $media->exists ? $media->fresh() : Media::find($media->getKey());
        } catch (\Throwable $e) {
            Log::debug('media_cleanup.conversion_status_unavailable', [
                'media_id' => $this->mediaId($media),
                'error'    => $e->getMessage(),
            ]);

            return [
                'complete'  => false,
                'expected'  => $expectedCount,
                'generated' => 0,
                'pending'   => $expectedCount,
                'ratio'     => 1.0,
            ];
        }

        if ($fresh === null) {
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

        $generated = 0;
        foreach ($expected as $conversion) {
            if ($fresh->hasGeneratedConversion($conversion)) {
                $generated++;
            }
        }

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

    private function findState(string $mediaId): ?MediaCleanupState
    {
        try {
            return MediaCleanupState::query()->find($mediaId);
        } catch (\Throwable $e) {
            Log::warning('media_cleanup.state_unavailable', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function saveOrDeleteState(MediaCleanupState $state): bool
    {
        if ($this->stateIsEmpty($state)) {
            if ($state->exists) {
                return $this->deleteState($state);
            }

            return true;
        }

        return $this->persistState($state);
    }

    private function stateIsEmpty(MediaCleanupState $state): bool
    {
        $conversions = $state->conversions ?? [];
        $payload = $state->payload ?? [];

        return empty($conversions) && empty($payload);
    }

    private function persistState(MediaCleanupState $state): bool
    {
        try {
            $state->save();
            return true;
        } catch (\Throwable $e) {
            Log::warning('media_cleanup.state_save_failed', [
                'media_id' => $state->media_id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteState(MediaCleanupState $state): bool
    {
        try {
            $state->delete();
            return true;
        } catch (\Throwable $e) {
            Log::warning('media_cleanup.state_delete_failed', [
                'media_id' => $state->media_id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }
}
