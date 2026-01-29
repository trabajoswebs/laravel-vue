<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Jobs;

use App\Application\Shared\Contracts\ClockInterface;
use App\Application\Shared\Contracts\LoggerInterface;
use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupAvatarOrphans;

/**
 * Job coalescedor: procesa únicamente el último avatar subido por usuario/tenant.
 *
 * Al encolar múltiples reemplazos rápidos, se guarda en Redis el último media_id
 * y un único job por (tenant,user) lee ese valor en ejecución, ignorando los
 * anteriores. Esto elimina las carreras que generaban warnings de media missing.
 */
final class ProcessLatestAvatar implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LAST_KEY_TTL    = 300; // 5 min para tracking del último avatar
    private const LOCK_TTL        = 60;  // Ventana de coalescing
    private const COLLECTION      = 'avatar';

    public function __construct(
        public readonly int|string $tenantId,
        public readonly int|string $userId,
    ) {
        $this->onQueue(config('queue.aliases.media', 'media'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return sprintf('avatar-coalesce:%s:%s', $this->tenantId, $this->userId);
    }

    public function uniqueFor(): int
    {
        return self::LOCK_TTL;
    }

    /**
     * Guarda en Redis el último media_id/upload_uuid para el usuario.
     */
    public static function rememberLatest(
        int|string $tenantId,
        int|string $userId,
        int|string $mediaId,
        string $uploadUuid,
        ?string $correlationId = null
    ): void {
        $payload = json_encode([
            'media_id'       => (string) $mediaId,
            'upload_uuid'    => $uploadUuid,
            'correlation_id' => $correlationId,
            'tenant_id'      => (string) $tenantId,
            'user_id'        => (string) $userId,
            'updated_at'     => app(ClockInterface::class)->now()->toIso8601String(),
        ]);

        try {
            Redis::setex(self::lastKey($tenantId, $userId), self::LAST_KEY_TTL, $payload ?: '');
        } catch (\Throwable $e) {
            app(LoggerInterface::class)->info('job.stale_skipped', [
                'reason' => 'redis_unavailable_last',
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Encola el coalescedor una sola vez por ventana LOCK_TTL.
     */
    public static function enqueueOnce(int|string $tenantId, int|string $userId): void
    {
        $lockKey = self::lockKey($tenantId, $userId);
        try {
            $acquired = Redis::set($lockKey, '1', 'EX', self::LOCK_TTL, 'NX');
        } catch (\Throwable $e) {
            // En entornos sin Redis (tests) degradar a dispatch directo
            app(LoggerInterface::class)->info('job.stale_skipped', [
                'reason' => 'redis_unavailable_lock',
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            self::dispatch($tenantId, $userId);
            return;
        }

        if ($acquired) {
            self::dispatch($tenantId, $userId);
        }
    }

    public function handle(): void
    {
        $iterations = 0;

        try {
            while ($iterations < 3) {
                ++$iterations;

                $payload = $this->readLatestPayload();
                if ($payload === null) {
                    return;
                }

                $mediaId = (int) $payload['media_id'];
                $corr    = $payload['correlation_id'] ?? $payload['upload_uuid'] ?? null;

                /** @var User|null $user */
                $user = User::query()->find($this->userId);
                if ($user === null) {
                    $this->staleSkip('user_missing', $mediaId, $corr);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                $tenant = Tenant::query()->find($this->tenantId);
                if ($tenant === null) {
                    $this->staleSkip('tenant_missing', $mediaId, $corr, ['user_id' => $this->userId]);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }
                $tenant->makeCurrent();

                $media = Media::query()->find($mediaId);
                if ($media === null) {
                    $this->staleSkip('media_missing', $mediaId, $corr, ['user_id' => $user->getKey()]);
                    $this->dispatchDirectCleanupFromPayload($payload, 'media_missing');
                    CleanupAvatarOrphans::dispatch($this->tenantId, $this->userId);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                if ($media->collection_name !== self::COLLECTION) {
                    $this->staleSkip('wrong_collection', $mediaId, $corr, ['collection' => $media->collection_name]);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                $current = $user->getFirstMedia(self::COLLECTION);
                if ($current === null || $current->getKey() !== $media->getKey()) {
                    $this->staleSkip('superseded', $mediaId, $corr, [
                        'latest_media_id' => $current?->getKey(),
                        'user_id'         => $user->getKey(),
                    ]);
                    $this->dispatchDirectCleanup($media, 'superseded');
                    CleanupAvatarOrphans::dispatch($this->tenantId, $this->userId);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                if (!$this->sourceExists($media)) {
                    $this->staleSkip('source_missing', $mediaId, $corr, ['disk' => $media->disk]);
                    if (!$this->shouldReprocess($mediaId)) {
                        return;
                    }
                    $this->refreshLockTtl();
                    continue;
                }

                PostProcessAvatarMedia::dispatchFor(
                    media: $media,
                    tenantId: $this->tenantId,
                    conversions: $this->conversions(),
                    collection: self::COLLECTION,
                    correlationId: $corr
                );

                if ($this->shouldReprocess($mediaId)) {
                    $this->refreshLockTtl();
                    continue;
                }

                return;
            }
        } finally {
            $this->releaseLock();
        }
    }

    private function readLatestPayload(): ?array
    {
        try {
            $raw = Redis::get(self::lastKey($this->tenantId, $this->userId));
        } catch (\Throwable $e) {
            $this->staleSkip('redis_unavailable_read', 0, null, ['error' => $e->getMessage()]);
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['media_id'], $decoded['upload_uuid'])) {
            return null;
        }

        return $decoded;
    }

    private function sourceExists(Media $media): bool
    {
        $relative = $media->getPathRelativeToRoot();
        if (!is_string($relative) || $relative === '') {
            return true;
        }

        return Storage::disk($media->disk)->exists($relative);
    }

    private function conversions(): array
    {
        $sizes = config('image-pipeline.avatar_sizes', [
            'thumb'  => 128,
            'medium' => 256,
            'large'  => 512,
        ]);

        $list = array_values(array_filter(
            array_keys(is_array($sizes) ? $sizes : []),
            static fn($value) => is_string($value) && $value !== ''
        ));

        return $list === [] ? ['thumb', 'medium', 'large'] : $list;
    }

    private function staleSkip(string $reason, int $mediaId, ?string $corr, array $extra = []): void
    {
        $context = array_merge([
            'reason'      => $reason,
            'media_id'    => $mediaId,
            'tenant_id'   => $this->tenantId,
            'user_id'     => $this->userId,
            'correlation' => $corr,
        ], $extra);

        app(LoggerInterface::class)->info('job.stale_skipped', $context);
    }

    /**
     * Encola cleanup directo a partir de un Media existente.
     */
    private function dispatchDirectCleanup(Media $media, string $reason): void
    {
        try {
            $artifacts = $this->artifactsForMedia($media);
            if ($artifacts === []) {
                return;
            }

            CleanupMediaArtifactsJob::dispatch($artifacts, []);

            app(LoggerInterface::class)->info('avatar.cleanup.direct_dispatched', [
                'media_id' => $media->getKey(),
                'reason'   => $reason,
                'disks'    => array_keys($artifacts),
                'tenant_id'=> $this->tenantId,
                'user_id'  => $this->userId,
            ]);
        } catch (\Throwable $e) {
            app(LoggerInterface::class)->warning('avatar.cleanup.direct_failed', [
                'media_id' => $media->getKey(),
                'reason'   => $reason,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Encola cleanup directo usando el payload Redis cuando el Media ya no existe.
     */
    private function dispatchDirectCleanupFromPayload(?array $payload, string $reason): void
    {
        if ($payload === null) {
            return;
        }

        $media = Media::query()->find($payload['media_id'] ?? null);
        if ($media instanceof Media) {
            $this->dispatchDirectCleanup($media, $reason . '_rehydrated');
            return;
        }

        app(LoggerInterface::class)->info('avatar.cleanup.direct_skipped_payload', [
            'reason'   => $reason,
            'media_id' => $payload['media_id'] ?? null,
            'tenant_id'=> $payload['tenant_id'] ?? null,
            'user_id'  => $payload['user_id'] ?? null,
        ]);

        // Fallback: ejecuta limpieza de huérfanos por usuario/tenant.
        CleanupAvatarOrphans::dispatch($this->tenantId, $this->userId);
    }

    /**
     * Construye artefactos (original + conversions + responsive) para un media.
     *
     * @return array<string,list<array{dir:string,mediaId:string}>>
     */
    private function artifactsForMedia(Media $media): array
    {
        $disk = (string) ($media->disk ?? '');
        if ($disk === '') {
            return [];
        }

        $conversionDisk = (string) ($media->conversions_disk ?: $media->disk);
        $pathGenerator = app(PathGenerator::class);
        $mediaId = (string) $media->getKey();

        $baseDir = rtrim($pathGenerator->getPath($media), '/');
        $convDir = rtrim($pathGenerator->getPathForConversions($media), '/');
        $respDir = rtrim($pathGenerator->getPathForResponsiveImages($media), '/');

        $artifacts = [
            $disk => [
                ['dir' => $baseDir, 'mediaId' => $mediaId],
            ],
        ];

        if ($conversionDisk !== '') {
            $artifacts[$conversionDisk] = array_merge($artifacts[$conversionDisk] ?? [], [
                ['dir' => $convDir, 'mediaId' => $mediaId],
                ['dir' => $respDir, 'mediaId' => $mediaId],
            ]);
        }

        return $artifacts;
    }

    private static function lastKey(int|string $tenantId, int|string $userId): string
    {
        return sprintf('ppam:avatar:last:%s:%s', $tenantId, $userId);
    }

    private static function lockKey(int|string $tenantId, int|string $userId): string
    {
        return sprintf('ppam:avatar:lock:%s:%s', $tenantId, $userId);
    }

    private function refreshLockTtl(): void
    {
        try {
            Redis::expire(self::lockKey($this->tenantId, $this->userId), self::LOCK_TTL);
        } catch (\Throwable) {
            // ignore refresh errors; lock expirará solo
        }
    }

    private function releaseLock(): void
    {
        try {
            Redis::del(self::lockKey($this->tenantId, $this->userId));
        } catch (\Throwable) {
            // ignore release errors; lock expirará solo
        }
    }

    private function shouldReprocess(int $mediaIdProcessed): bool
    {
        $latest = $this->readLatestPayload();
        if ($latest === null) {
            return false;
        }

        return (int) $latest['media_id'] !== $mediaIdProcessed;
    }
}
