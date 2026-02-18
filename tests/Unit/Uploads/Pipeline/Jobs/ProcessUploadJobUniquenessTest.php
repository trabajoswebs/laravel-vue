<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Jobs;

use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Jobs\ProcessUploadJob;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Modules\Uploads\Pipeline\Support\QuarantineManager;
use Tests\TestCase;

final class ProcessUploadJobUniquenessTest extends TestCase
{
    public function test_unique_id_is_stable_for_same_token(): void
    {
        $token = QuarantineToken::fromPath('/tmp/fake.bin', 'q/a.bin', 'cid', 'avatar');

        $jobA = new ProcessUploadJob($token, '7', \App\Modules\Uploads\Profiles\AvatarProfile::class, 'cid');
        $jobB = new ProcessUploadJob($token, '7', \App\Modules\Uploads\Profiles\AvatarProfile::class, 'cid');

        $this->assertSame($jobA->uniqueId(), $jobB->uniqueId());
    }

    public function test_failed_cleans_up_quarantine_artifact(): void
    {
        $token = QuarantineToken::fromPath('/tmp/fake.bin', 'q/a.bin', 'cid', 'avatar');
        $job = new ProcessUploadJob($token, '7', \App\Modules\Uploads\Profiles\AvatarProfile::class, 'cid');

        $repository = $this->createMock(QuarantineRepository::class);
        $repository->expects($this->once())
            ->method('delete')
            ->with($token);

        $this->app->instance(QuarantineManager::class, new QuarantineManager($repository));

        $job->failed(new UploadValidationException('failed'));
    }

    public function test_job_targets_media_queue_and_after_commit(): void
    {
        $token = QuarantineToken::fromPath('/tmp/fake.bin', 'q/a.bin', 'cid', 'avatar');
        $job = new ProcessUploadJob($token, '7', \App\Modules\Uploads\Profiles\AvatarProfile::class, 'cid');

        $this->assertSame(config('queue.aliases.media', 'media'), $job->queue);
        $this->assertTrue((bool) $job->afterCommit);
    }
}
