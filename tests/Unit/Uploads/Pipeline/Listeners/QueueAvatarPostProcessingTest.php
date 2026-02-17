<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Listeners;

use App\Support\Contracts\LoggerInterface;
use App\Infrastructure\Uploads\Pipeline\Jobs\ProcessLatestAvatar;
use App\Infrastructure\Uploads\Pipeline\Listeners\QueueAvatarPostProcessing;
use Illuminate\Support\Facades\Queue;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class QueueAvatarPostProcessingTest extends TestCase
{
    public function test_ignores_unlisted_conversion_event(): void
    {
        config()->set('image-pipeline.postprocess_collections', ['avatar']);
        config()->set('image-pipeline.avatar_sizes', ['thumb' => 128]);

        Queue::fake();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'ppam_listener_skip_conversion',
                $this->callback(static fn (array $context): bool => ($context['conversion_fired'] ?? null) === 'large')
            );

        $listener = new QueueAvatarPostProcessing($logger);

        $media = new Media();
        $media->id = 321;
        $media->collection_name = 'avatar';
        $media->model_id = 45;
        $media->custom_properties = [
            'tenant_id' => 12,
            'upload_uuid' => 'upload-uuid-1',
        ];

        $event = new class($media) {
            public function __construct(
                public Media $media,
                public string $conversionName = 'large',
            ) {
            }
        };

        $listener->handle($event);

        Queue::assertNotPushed(ProcessLatestAvatar::class);
    }
}

