<?php

declare(strict_types=1);

namespace App\Support\Media\Services;

use App\Jobs\CleanupMediaArtifactsJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Orquesta la limpieza de artefactos asegurando que las conversions hayan finalizado.
 *
 * Flujo:
 *  - Cada nuevo Media marcado con conversions asincrónicas se registra como "pendiente".
 *  - Al reemplazar un Media, si sigue pendiente se pospone el cleanup y se guarda el payload.
 *  - Cuando Spatie emite ConversionHasBeenCompleted/Failed, se ejecuta el cleanup encolado.
 */
final class MediaCleanupScheduler
{
    /** TTL para banderas y payloads (en minutos). */
    private const CACHE_TTL_MINUTES = 120;

    /** Prefijos para claves de cache. */
    private const KEY_PENDING = 'media_cleanup:pending:';   // bandera conversions pendientes
    private const KEY_PAYLOAD = 'media_cleanup:payload:';   // payload diferido de cleanup

    /**
     * Marca un Media como pendiente de conversions si corresponde.
     *
     * @param array<int,string> $expectedConversions
     */
    public function flagPendingConversions(Media $media, array $expectedConversions): void
    {
        $mediaId = $this->mediaId($media);
        $conversions = $this->normalizeConversions($expectedConversions);

        if ($conversions === []) {
            $this->forgetPendingFlag($mediaId);
            return;
        }

        // Si ya existen todas las conversions generadas no hace falta marcarlo como pendiente.
        if ($this->conversionsCompleted($media, $conversions, false)) {
            $this->forgetPendingFlag($mediaId);
            return;
        }

        $this->rememberPendingFlag($mediaId, $conversions);

        Log::debug('media_cleanup.pending_flagged', [
            'media_id'     => $mediaId,
            'collection'   => $media->collection_name,
            'conversions'  => $conversions,
        ]);
    }

    /**
     * Agenda la limpieza de artefactos. Si aún hay conversions pendientes, se pospone.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $preserveMediaIds
     * @param array<int,string> $expectedConversions
     */
    public function scheduleCleanup(
        Media $media,
        array $artifacts,
        array $preserveMediaIds,
        array $expectedConversions
    ): void {
        if ($artifacts === []) {
            return;
        }

        $mediaId = $this->mediaId($media);
        $conversions = $this->normalizeConversions($expectedConversions);
        $hasPendingFlag = $this->hasPendingConversions($mediaId);

        if (!$hasPendingFlag) {
            if ($this->conversionsCompleted($media, $conversions, false)) {
                $this->dispatchCleanup($artifacts, $preserveMediaIds);
                return;
            }

            $this->storePayload($mediaId, $artifacts, $preserveMediaIds, $conversions);
            return;
        }

        $this->storePayload($mediaId, $artifacts, $preserveMediaIds, $conversions);
    }

    /**
     * Procesa eventos de conversions completadas o fallidas.
     *
     * @param Media $media Instancia informada por Spatie.
     */
    public function handleConversionEvent(Media $media): void
    {
        $mediaId = $this->mediaId($media);

        $pending = $this->pendingFlag($mediaId);
        $payload = $this->payloadFor($mediaId);

        if ($pending === null && $payload === null) {
            return;
        }

        $conversions = $this->normalizeConversions(
            $payload['conversions'] ?? ($pending['conversions'] ?? [])
        );

        if (!$this->conversionsCompleted($media, $conversions, true)) {
            // Aún queda alguna conversion activa; conservar banderas/payload.
            return;
        }

        $this->forgetPendingFlag($mediaId);
        $payload = $this->pullPayloadFor($mediaId);

        if ($payload !== null) {
            $this->dispatchCleanup(
                $payload['artifacts'] ?? [],
                $payload['preserve'] ?? []
            );
        }
    }

    /**
     * Intenta ejecutar limpiezas diferidas si la bandera expiró.
     *
     * Se usa como red de seguridad por si la cache expira antes del evento.
     *
     * @param string $mediaId
     */
    public function flushExpired(string $mediaId): void
    {
        $payload = $this->pullPayloadFor($mediaId);
        $this->forgetPendingFlag($mediaId);

        if ($payload !== null) {
            $this->dispatchCleanup(
                $payload['artifacts'] ?? [],
                $payload['preserve'] ?? []
            );
        }
    }

    /**
     * ¿Existe bandera de conversions pendientes para el media?
     */
    private function hasPendingConversions(string $mediaId): bool
    {
        return Cache::has($this->pendingKey($mediaId));
    }

    /**
     * ¿Las conversions esperadas ya fueron generadas?
     *
     * @param Media $media
     * @param array<int,string> $expected
     */
    private function conversionsCompleted(Media $media, array $expected, bool $missingIsComplete): bool
    {
        if ($expected === []) {
            return true;
        }

        try {
            $fresh = $media->exists ? $media->fresh() : Media::find($media->getKey());
        } catch (\Throwable $e) {
            Log::debug('media_cleanup.conversion_status_unavailable', [
                'media_id' => $this->mediaId($media),
                'error'    => $e->getMessage(),
            ]);
            return false;
        }

        if ($fresh === null) {
            return $missingIsComplete;
        }

        foreach ($expected as $conversion) {
            if (!$fresh->hasGeneratedConversion($conversion)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Encola el job de limpieza con artefactos agregados.
     *
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $preserveMediaIds
     */
    private function dispatchCleanup(array $artifacts, array $preserveMediaIds): void
    {
        if ($artifacts === []) {
            return;
        }

        CleanupMediaArtifactsJob::dispatch($artifacts, $preserveMediaIds);

        Log::info('media_cleanup.dispatched', [
            'disks'           => array_keys($artifacts),
            'preserve'        => $preserveMediaIds,
            'artifact_count'  => array_sum(array_map('count', $artifacts)),
        ]);
    }

    /**
     * Persist payload en cache hasta que finalicen las conversions.
     *
     * @param string $mediaId
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string> $preserveMediaIds
     * @param array<int,string> $conversions
     */
    private function storePayload(
        string $mediaId,
        array $artifacts,
        array $preserveMediaIds,
        array $conversions
    ): void {
        Cache::put(
            $this->payloadKey($mediaId),
            [
                'artifacts'   => $artifacts,
                'preserve'    => array_values(array_unique(array_map('strval', $preserveMediaIds))),
                'conversions' => $conversions,
                'queued_at'   => now()->toIso8601String(),
            ],
            now()->addMinutes(self::CACHE_TTL_MINUTES)
        );

        Log::info('media_cleanup.deferred', [
            'media_id' => $mediaId,
            'disks'    => array_keys($artifacts),
        ]);
    }

    /**
     * Filtra y normaliza nombres de conversions.
     *
     * @param array<int,string> $conversions
     * @return array<int,string>
     */

    private function rememberPendingFlag(string $mediaId, array $conversions): void
    {
        Cache::put(
            $this->pendingKey($mediaId),
            [
                'conversions' => $conversions,
                'flagged_at'  => now()->toIso8601String(),
            ],
            now()->addMinutes(self::CACHE_TTL_MINUTES)
        );
    }

    private function pendingFlag(string $mediaId): ?array
    {
        $value = Cache::get($this->pendingKey($mediaId));

        return is_array($value) ? $value : null;
    }

    private function forgetPendingFlag(string $mediaId): void
    {
        Cache::forget($this->pendingKey($mediaId));
    }

    private function payloadFor(string $mediaId): ?array
    {
        $value = Cache::get($this->payloadKey($mediaId));

        return is_array($value) ? $value : null;
    }

    private function pullPayloadFor(string $mediaId): ?array
    {
        $value = Cache::pull($this->payloadKey($mediaId));

        return is_array($value) ? $value : null;
    }

    private function normalizeConversions(array $conversions): array
    {
        $filtered = array_filter($conversions, static fn ($name) => is_string($name) && $name !== '');
        $unique = array_values(array_unique(array_map(static fn ($name) => (string) $name, $filtered)));

        return $unique;
    }

    private function mediaId(Media $media): string
    {
        return (string) $media->getKey();
    }

    private function pendingKey(string $mediaId): string
    {
        return self::KEY_PENDING . $mediaId;
    }

    private function payloadKey(string $mediaId): string
    {
        return self::KEY_PAYLOAD . $mediaId;
    }
}
