<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Listeners;

use App\Application\User\Events\AvatarUpdated;
use App\Infrastructure\Uploads\Pipeline\Jobs\PostProcessAvatarMedia;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Reacciona a la actualización de avatar lanzando el post-procesado de media.
 */
final class OnAvatarUpdated
{
    public function handle(AvatarUpdated $event): void
    {
        $media = Media::query()->find($event->newMediaId);

        if ($media === null) {
            Log::warning('avatar.updated.media_missing', [
                'media_id' => $event->newMediaId,
                'user_id' => $event->userId,
            ]);
            return;
        }

        PostProcessAvatarMedia::dispatchFor(
            media: $media,
            conversions: [],
            collection: $event->collection,
            correlationId: $event->version, // reusa version como correlación si existe
        );
    }
}
