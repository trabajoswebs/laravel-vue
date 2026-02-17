<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\User;
use App\Models\Tenant;
use App\Modules\Uploads\Pipeline\Jobs\PostProcessAvatarMedia;
use App\Modules\Uploads\Pipeline\Jobs\ProcessLatestAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

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

        $payload = json_encode([
            'media_id' => (string) $mediaNew->getKey(),
            'upload_uuid' => 'u-new',
            'correlation_id' => 'corr-new',
            'tenant_id' => (string) $tenant->getKey(),
            'user_id' => (string) $user->getKey(),
            'updated_at' => now()->toIso8601String(),
        ]);
        $redis->shouldReceive('get')->andReturnUsing(static function (string $key) use ($payload): string {
            if (str_contains($key, 'ppam:avatar:ver:')) {
                return '1';
            }

            return $payload;
        });

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
        $payload = json_encode([
            'media_id' => '9999',
            'upload_uuid' => 'u-missing',
            'tenant_id' => '1',
            'user_id' => '1',
            'updated_at' => now()->toIso8601String(),
        ]);
        $redis->shouldReceive('get')->andReturnUsing(static function (string $key) use ($payload): string {
            if (str_contains($key, 'ppam:avatar:ver:')) {
                return '1';
            }

            return $payload;
        });
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

        $payloads = [
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
        ];
        $payloadRead = 0;
        $redis->shouldReceive('get')->andReturnUsing(static function (string $key) use (&$payloadRead, $payloads): string {
            if (str_contains($key, 'ppam:avatar:ver:')) {
                return '1';
            }

            $index = min($payloadRead, count($payloads) - 1);
            $payloadRead++;
            return $payloads[$index];
        });

        $job = new ProcessLatestAvatar($tenant->getKey(), $user->getKey());
        $job->handle();

        Queue::assertPushed(PostProcessAvatarMedia::class, function (PostProcessAvatarMedia $queued) use ($mediaNew, $tenant) {
            return (int) $queued->mediaId === (int) $mediaNew->getKey()
                && (int) $queued->tenantId === (int) $tenant->getKey();
        });
    }

    public function test_requeues_when_latest_changes_at_job_end(): void
    {
        Storage::fake('public');
        Queue::fake();
        $redis = Redis::partialMock();
        $redis->shouldReceive('del')->andReturn(1);
        $redis->shouldReceive('set')->andReturn('OK');

        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'owner_user_id' => $user->getKey(),
        ]);
        $tenant->makeCurrent();

        $media = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->getKey(),
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'collection_name' => 'avatar',
            'name' => 'avatar-current',
            'file_name' => 'avatar-current.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 1024,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->getKey(), 'upload_uuid' => 'u-current'],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        $path = $media->getPathRelativeToRoot();
        if (is_string($path)) {
            Storage::disk('public')->put($path, 'avatar-current');
        }

        $payload = json_encode([
            'media_id' => (string) $media->getKey(),
            'upload_uuid' => 'u-current',
            'correlation_id' => 'corr-current',
            'tenant_id' => (string) $tenant->getKey(),
            'user_id' => (string) $user->getKey(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $versionRead = 0;
        $redis->shouldReceive('get')->andReturnUsing(static function (string $key) use (&$versionRead, $payload): string {
            if (str_contains($key, 'ppam:avatar:ver:')) {
                $versionRead++;
                return $versionRead === 1 ? '1' : '2';
            }

            return $payload;
        });

        $job = new ProcessLatestAvatar($tenant->getKey(), $user->getKey());
        $job->handle();

        Queue::assertPushed(ProcessLatestAvatar::class, 1);
    }
}
