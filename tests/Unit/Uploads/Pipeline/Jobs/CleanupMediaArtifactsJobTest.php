<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Jobs;

use App\Support\Contracts\LoggerInterface;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CleanupMediaArtifactsJobTest extends TestCase
{
    public function test_unique_id_is_stable_for_equivalent_payloads(): void
    {
        $artifactsA = [
            'public' => [
                ['dir' => 'tenants/1/users/1/avatars/uuid-a/conversions', 'mediaId' => '10'],
                ['dir' => 'tenants/1/users/1/avatars/uuid-a', 'mediaId' => '10'],
            ],
            's3' => [
                ['dir' => 'tenants/1/users/1/avatars/uuid-a/responsive-images', 'mediaId' => '10'],
            ],
        ];

        $artifactsB = [
            's3' => [
                ['dir' => 'tenants/1/users/1/avatars/uuid-a/responsive-images', 'mediaId' => '10'],
            ],
            'public' => [
                ['dir' => 'tenants/1/users/1/avatars/uuid-a', 'mediaId' => '10'],
                ['dir' => 'tenants/1/users/1/avatars/uuid-a/conversions', 'mediaId' => '10'],
            ],
        ];

        $jobA = new CleanupMediaArtifactsJob($artifactsA, ['10', '11']);
        $jobB = new CleanupMediaArtifactsJob($artifactsB, ['11', '10']);

        $this->assertSame($jobA->uniqueId(), $jobB->uniqueId());
    }

    public function test_delete_directory_safely_marks_unparsable_tenant_first_legacy_without_media_id(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tenants/1/users/5/avatars/uuid-a/file.jpg', 'x');

        $job = new CleanupMediaArtifactsJob([]);
        $method = new \ReflectionMethod($job, 'deleteDirectorySafely');
        $method->setAccessible(true);

        $result = $method->invoke(
            $job,
            Storage::disk('public'),
            'public',
            'tenants/1/users/5/avatars/uuid-a',
            null
        );

        $this->assertSame('skipped_legacy_unparsable', $result);
        $this->assertTrue(Storage::disk('public')->exists('tenants/1/users/5/avatars/uuid-a/file.jpg'));
    }

    public function test_delete_directory_safely_accepts_tenant_first_legacy_media_segment_without_media_id(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tenants/1/users/5/media/77/conversions/thumb.webp', 'x');

        $job = new CleanupMediaArtifactsJob([]);
        $method = new \ReflectionMethod($job, 'deleteDirectorySafely');
        $method->setAccessible(true);

        $result = $method->invoke(
            $job,
            Storage::disk('public'),
            'public',
            'tenants/1/users/5/media/77/conversions',
            null
        );

        $this->assertSame('deleted', $result);
        $this->assertFalse(Storage::disk('public')->exists('tenants/1/users/5/media/77/conversions/thumb.webp'));
    }

    public function test_handle_aggregates_skipped_legacy_unparsable_stat(): void
    {
        Storage::fake('public');

        $logger = new class implements LoggerInterface {
            /** @var array<int,array{level:string,message:string,context:array}> */
            public array $records = [];

            public function debug(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
            }

            public function info(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            public function notice(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            public function error(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }

            public function critical(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
            }

            public function alert(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'alert', 'message' => $message, 'context' => $context];
            }
        };
        app()->instance(LoggerInterface::class, $logger);

        $job = new CleanupMediaArtifactsJob([
            'public' => [
                ['dir' => 'tenants/1/users/5/avatars/uuid-a'],
            ],
        ]);

        $job->handle();

        $completedLog = null;
        foreach ($logger->records as $record) {
            if ($record['level'] === 'info' && $record['message'] === 'cleanup_media_artifacts_completed') {
                $completedLog = $record;
                break;
            }
        }

        $this->assertIsArray($completedLog);
        $stats = $completedLog['context']['stats'] ?? null;
        $this->assertIsArray($stats);
        $this->assertSame(1, $stats['skipped_legacy_unparsable'] ?? null);
    }

    public function test_delete_directory_safely_rejects_non_tenant_first_paths_even_with_media_id(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('users/5/avatars/uuid-a/file.jpg', 'x');

        $job = new CleanupMediaArtifactsJob([]);
        $method = new \ReflectionMethod($job, 'deleteDirectorySafely');
        $method->setAccessible(true);

        $result = $method->invoke(
            $job,
            Storage::disk('public'),
            'public',
            'users/5/avatars/uuid-a',
            '10'
        );

        $this->assertSame('skipped_invalid', $result);
        $this->assertTrue(Storage::disk('public')->exists('users/5/avatars/uuid-a/file.jpg'));
    }
}
