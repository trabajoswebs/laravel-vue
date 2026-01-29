<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Jobs;

use App\Application\Shared\Contracts\LoggerInterface;
use App\Infrastructure\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Limpia directorios de avatares que ya no tienen un Media asociado.
 *
 * Recorre tenants/{tenant}/users/{user}/avatars/* y elimina cualquier
 * subdirectorio cuyo UUID no exista en la tabla media. Es idempotente y
 * seguro frente a carreras porque verifica existencia antes de borrar.
 */
final class CleanupAvatarOrphans implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int|string $tenantId,
        public readonly int|string $userId,
        public readonly ?string $disk = null,
    ) {
        $this->onQueue(config('queue.aliases.media', 'media'));
        $this->afterCommit();
    }

    public function handle(): void
    {
        $disk = $this->disk ?: (string) (config('image-pipeline.avatar_disk') ?: config('filesystems.default', 'public'));
        $logger = app(LoggerInterface::class);

        // Fija tenant si existe
        if ($tenant = Tenant::query()->find($this->tenantId)) {
            $tenant->makeCurrent();
        }

        $fs = Storage::disk($disk);
        $base = sprintf('tenants/%s/users/%s/avatars', $this->tenantId, $this->userId);

        if (!$fs->exists($base)) {
            return;
        }

        $dirs = $fs->directories($base);
        $deleted = 0;
        $kept = 0;
        $missing = 0;

        foreach ($dirs as $dir) {
            $uuid = basename($dir);
            if ($uuid === '' || $uuid === false) {
                continue;
            }

            // Verifica si hay Media con ese uuid
            $exists = Media::query()->where('uuid', $uuid)->exists();

            if ($exists) {
                $kept++;
                continue;
            }

            try {
                $fs->deleteDirectory($dir);
                $deleted++;
            } catch (\Throwable $e) {
                $missing++;
                $logger->warning('avatar.cleanup.orphan_delete_failed', [
                    'dir' => $dir,
                    'error' => $e->getMessage(),
                    'tenant_id' => $this->tenantId,
                    'user_id' => $this->userId,
                    'disk' => $disk,
                ]);
            }
        }

        $logger->info('avatar.cleanup.orphans_completed', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'disk' => $disk,
            'deleted' => $deleted,
            'kept' => $kept,
            'missing' => $missing,
            'base' => $base,
        ]);
    }
}
