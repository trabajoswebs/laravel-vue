<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Observers;

use App\Modules\Uploads\Pipeline\Jobs\CleanupAvatarOrphans;
use App\Modules\Uploads\Pipeline\Observers\MediaObserver;
use Illuminate\Support\Facades\Bus;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class MediaObserverTest extends TestCase
{
    public function test_deleted_does_not_dispatch_orphan_cleanup_for_s3_disks(): void
    {
        config()->set('filesystems.disks.private_s3.driver', 's3');
        Bus::fake();

        $media = new Media();
        $media->forceFill([
            'id' => 15,
            'disk' => 'private_s3',
            'model_id' => 9,
            'collection_name' => 'avatar',
            'custom_properties' => ['tenant_id' => 1],
        ]);

        (new MediaObserver())->deleted($media);

        Bus::assertNotDispatched(CleanupAvatarOrphans::class);
    }

    public function test_deleted_dispatches_orphan_cleanup_for_local_disks(): void
    {
        config()->set('filesystems.disks.local_private.driver', 'local');
        Bus::fake();

        $media = new Media();
        $media->forceFill([
            'id' => 16,
            'disk' => 'local_private',
            'model_id' => 10,
            'collection_name' => 'avatar',
            'custom_properties' => ['tenant_id' => 2],
        ]);

        (new MediaObserver())->deleted($media);

        Bus::assertDispatched(CleanupAvatarOrphans::class, function (CleanupAvatarOrphans $job): bool {
            return (string) $job->tenantId === '2' && (string) $job->userId === '10';
        });
    }

    public function test_deleted_does_not_dispatch_orphan_cleanup_for_non_avatar_collections(): void
    {
        config()->set('filesystems.disks.local_private.driver', 'local');
        Bus::fake();

        $media = new Media();
        $media->forceFill([
            'id' => 17,
            'disk' => 'local_private',
            'model_id' => 11,
            'collection_name' => 'documents',
            'custom_properties' => ['tenant_id' => 3],
        ]);

        (new MediaObserver())->deleted($media);

        Bus::assertNotDispatched(CleanupAvatarOrphans::class);
    }
}
