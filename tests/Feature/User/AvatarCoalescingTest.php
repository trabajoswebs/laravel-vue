<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use App\Infrastructure\Uploads\Pipeline\Jobs\PostProcessAvatarMedia;
use App\Infrastructure\Uploads\Pipeline\Jobs\ProcessLatestAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use function app;

final class AvatarCoalescingTest extends TestCase
{
    use RefreshDatabase;

    public function test_coalescer_processes_only_latest_media(): void
    {
        Storage::fake('public');
        Queue::fake();
        $redis = Redis::partialMock();
        $redis->shouldReceive('del')->andReturn(1);

        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);
        $tenant->makeCurrent();

        $mediaOld = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'collection_name' => 'avatar',
            'name' => 'avatar-old',
            'file_name' => 'avatar-old.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->getKey(), 'upload_uuid' => 'u-old'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 2,
        ]);

        $mediaNew = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'collection_name' => 'avatar',
            'name' => 'avatar-new',
            'file_name' => 'avatar-new.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->getKey(), 'upload_uuid' => 'u-new'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $path = $mediaNew->getPathRelativeToRoot();
        if (is_string($path)) {
            Storage::disk('public')->put($path, 'avatar');
        }

        $redis->shouldReceive('get')->andReturn(json_encode([
            'media_id' => (string) $mediaNew->getKey(),
            'upload_uuid' => 'u-new',
            'correlation_id' => 'corr-new',
            'tenant_id' => (string) $tenant->getKey(),
            'user_id' => (string) $user->getKey(),
            'updated_at' => now()->toIso8601String(),
        ]));

        $job = new ProcessLatestAvatar($tenant->getKey(), $user->getKey());
        $job->handle();

        Queue::assertPushed(PostProcessAvatarMedia::class, function (PostProcessAvatarMedia $queued) use ($mediaNew, $tenant) {
            return (int) $queued->mediaId === (int) $mediaNew->getKey()
                && (int) $queued->tenantId === (int) $tenant->getKey();
        });
    }

    public function test_coalescer_skips_missing_media_silently(): void
    {
        Queue::fake();
        $redis = Redis::partialMock();
        $redis->shouldReceive('get')->andReturn(json_encode([
            'media_id' => '9999',
            'upload_uuid' => 'u-missing',
            'tenant_id' => '1',
            'user_id' => '1',
            'updated_at' => now()->toIso8601String(),
        ]));
        $redis->shouldReceive('del')->andReturn(1);

        $job = new ProcessLatestAvatar(1, 1);
        $job->handle();

        Queue::assertNothingPushed();
    }

    public function test_coalescer_reprocesses_when_latest_changes_mid_run(): void
    {
        Storage::fake('public');
        Queue::fake();
        $redis = Redis::partialMock();
        $redis->shouldReceive('del')->andReturn(1);
        $redis->shouldReceive('expire')->andReturn(1);

        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);
        $tenant->makeCurrent();

        $mediaOld = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'collection_name' => 'avatar',
            'name' => 'avatar-old',
            'file_name' => 'avatar-old.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->getKey(), 'upload_uuid' => 'u-old'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 2,
        ]);

        $mediaNew = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'collection_name' => 'avatar',
            'name' => 'avatar-new',
            'file_name' => 'avatar-new.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->getKey(), 'upload_uuid' => 'u-new'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $pathNew = $mediaNew->getPathRelativeToRoot();
        if (is_string($pathNew)) {
            Storage::disk('public')->put($pathNew, 'avatar-new');
        }

        // Primera lectura: devuelve mediaOld; segunda y tercera: mediaNew (para shouldReprocess + siguiente iteración)
        $redis->shouldReceive('get')->andReturn(
            json_encode([
                'media_id' => (string) $mediaOld->getKey(),
                'upload_uuid' => 'u-old',
                'correlation_id' => 'corr-old',
                'tenant_id' => (string) $tenant->getKey(),
                'user_id' => (string) $user->getKey(),
                'updated_at' => now()->subSecond()->toIso8601String(),
            ]),
            json_encode([
                'media_id' => (string) $mediaNew->getKey(),
                'upload_uuid' => 'u-new',
                'correlation_id' => 'corr-new',
                'tenant_id' => (string) $tenant->getKey(),
                'user_id' => (string) $user->getKey(),
                'updated_at' => now()->toIso8601String(),
            ]),
            json_encode([
                'media_id' => (string) $mediaNew->getKey(),
                'upload_uuid' => 'u-new',
                'correlation_id' => 'corr-new',
                'tenant_id' => (string) $tenant->getKey(),
                'user_id' => (string) $user->getKey(),
                'updated_at' => now()->toIso8601String(),
            ]),
        );

        $job = new ProcessLatestAvatar($tenant->getKey(), $user->getKey());
        $job->handle();

        Queue::assertPushed(PostProcessAvatarMedia::class, function (PostProcessAvatarMedia $queued) use ($mediaNew, $tenant) {
            return (int) $queued->mediaId === (int) $mediaNew->getKey()
                && (int) $queued->tenantId === (int) $tenant->getKey();
        });
    }
}
