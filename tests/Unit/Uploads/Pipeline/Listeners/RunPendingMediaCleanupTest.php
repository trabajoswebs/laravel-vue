<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Listeners;

use App\Support\Contracts\LoggerInterface;
use App\Modules\Uploads\Contracts\MediaCleanupScheduler;
use App\Infrastructure\Uploads\Pipeline\Listeners\RunPendingMediaCleanup;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class RunPendingMediaCleanupTest extends TestCase
{
    public function test_handle_dispatches_cleanup_for_allowed_disk(): void
    {
        config()->set('media.allowed_disks', ['public']);

        $scheduler = $this->createMock(MediaCleanupScheduler::class);
        $scheduler->expects($this->once())->method('handleConversionEvent');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $listener = new RunPendingMediaCleanup($scheduler, $logger);

        $media = new Media();
        $media->id = 10;
        $media->disk = 'public';

        $event = new class($media) {
            public function __construct(public Media $media) {}
        };

        $listener->handle($event);
    }

    public function test_handle_skips_cleanup_for_disallowed_disk(): void
    {
        config()->set('media.allowed_disks', ['public']);

        $scheduler = $this->createMock(MediaCleanupScheduler::class);
        $scheduler->expects($this->never())->method('handleConversionEvent');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $listener = new RunPendingMediaCleanup($scheduler, $logger);

        $media = new Media();
        $media->id = 11;
        $media->disk = 's3';

        $event = new class($media) {
            public function __construct(public Media $media) {}
        };

        $listener->handle($event);
    }
}
