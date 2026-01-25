<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Application\User\Events\AvatarDeleted;
use App\Application\User\Events\AvatarUpdated;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use App\Infrastructure\Uploads\Pipeline\Jobs\PostProcessAvatarMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class AvatarEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_updated_event_enqueues_post_process_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $media = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => Str::uuid()->toString(),
            'collection_name' => 'avatar',
            'name' => 'avatar',
            'file_name' => 'avatar.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        event(new AvatarUpdated(
            $user->getKey(),
            $media->getKey(),
            null,
            'v1',
            'avatar',
            null,
        ));

        Queue::assertPushed(PostProcessAvatarMedia::class, function (PostProcessAvatarMedia $job) use ($media) {
            return (int) $job->mediaId === (int) $media->getKey();
        });
    }

    public function test_avatar_deleted_event_enqueues_cleanup_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        event(new AvatarDeleted(
            userId: (int) $user->getKey(),
            mediaId: 999,
        ));

        Queue::assertPushed(CleanupMediaArtifactsJob::class);
    }
}
