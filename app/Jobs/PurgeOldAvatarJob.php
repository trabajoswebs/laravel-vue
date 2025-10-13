<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Job que elimina el avatar antiguo de manera segura e idempotente.
 */
final class PurgeOldAvatarJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Espera a que la transacción de origen se confirme antes de ejecutarse.
     */
    public bool $afterCommit = true;

    /**
     * Cola dedicada para operaciones de media.
     */
    public string $queue = 'media';

    /**
     * Número máximo de reintentos por fallos transitorios.
     */
    public int $tries = 5;

    /**
     * Backoff progresivo entre reintentos (segundos).
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

    /**
     * Momento (segundos) durante el cual la unicidad se mantiene.
     */
    public int $uniqueFor = 300;

    public int $userId;

    public ?int $oldMediaId;

    public ?int $newMediaId;

    public function __construct(int $userId, ?int $oldMediaId, ?int $newMediaId)
    {
        $this->userId = $userId;
        $this->oldMediaId = $oldMediaId;
        $this->newMediaId = $newMediaId;

        $this->onQueue($this->queue);
    }

    /**
     * Clave única para evitar duplicados simultáneos.
     */
    public function uniqueId(): string
    {
        $old = $this->oldMediaId !== null ? (string) $this->oldMediaId : 'null';
        $new = $this->newMediaId !== null ? (string) $this->newMediaId : 'null';

        return sprintf('purge:%d:%s:%s', $this->userId, $old, $new);
    }

    /**
     * Maneja la eliminación segura del avatar antiguo.
     */
    public function handle(): void
    {
        if (empty($this->oldMediaId)) {
            Log::debug('PurgeOldAvatarJob: no oldMediaId provided, nothing to do.', [
                'user_id'      => $this->userId,
                'new_media_id' => $this->newMediaId,
            ]);
            return;
        }

        /** @var Media|null $oldMedia */
        $oldMedia = Media::find($this->oldMediaId);

        if (! $oldMedia) {
            Log::debug('PurgeOldAvatarJob: old media not found (already deleted).', [
                'user_id'      => $this->userId,
                'old_media_id' => $this->oldMediaId,
            ]);
            return;
        }

        if ($this->newMediaId !== null && $oldMedia->id === $this->newMediaId) {
            Log::warning('PurgeOldAvatarJob: old media equals new media, skipping.', [
                'user_id'  => $this->userId,
                'media_id' => $oldMedia->id,
            ]);
            return;
        }

        if ((int) $oldMedia->model_id !== $this->userId) {
            Log::warning('PurgeOldAvatarJob: media model_id does not match userId, skipping.', [
                'event_user_id'  => $this->userId,
                'media_model_id' => $oldMedia->model_id,
                'old_media_id'   => $oldMedia->id,
            ]);
            return;
        }

        try {
            $oldMedia->delete();

            Log::info('PurgeOldAvatarJob: old media deleted successfully.', [
                'user_id'      => $this->userId,
                'old_media_id' => $this->oldMediaId,
            ]);
        } catch (\Throwable $e) {
            Log::error('PurgeOldAvatarJob: failed to delete old media.', [
                'user_id'      => $this->userId,
                'old_media_id' => $this->oldMediaId,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

