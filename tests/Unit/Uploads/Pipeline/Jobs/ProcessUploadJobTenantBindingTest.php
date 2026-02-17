<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Jobs;

use App\Support\Contracts\MetricsInterface;
use App\Application\User\Contracts\UserRepository;
use App\Models\User;
use App\Modules\Uploads\Pipeline\DefaultUploadService;
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Jobs\ProcessUploadJob;
use App\Infrastructure\Uploads\Pipeline\Quarantine\LocalQuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProcessUploadJobTenantBindingTest extends TestCase
{
    public function test_handle_rejects_owner_tenant_mismatch_before_processing(): void
    {
        Storage::fake('quarantine');

        $token = QuarantineToken::fromPath('/tmp/fake.bin', 'q/a.bin', 'cid', 'avatar');
        $job = new ProcessUploadJob(
            token: $token,
            ownerId: '7',
            profileClass: \App\Infrastructure\Uploads\Profiles\AvatarProfile::class,
            correlationId: 'cid',
            tenantId: 99,
        );
        $job->withFakeQueueInteractions();

        $owner = new User();
        $owner->forceFill(['id' => 7, 'current_tenant_id' => 1]);

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('lockAndFindById')
            ->with('7')
            ->willReturn($owner);

        $uploader = (new \ReflectionClass(DefaultUploadService::class))->newInstanceWithoutConstructor();

        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->expects($this->once())
            ->method('increment')
            ->with(
                'upload.jobs.validation_failed',
                $this->callback(static fn(array $tags): bool => isset($tags['profile']) && $tags['profile'] !== '')
            );
        $quarantine = new QuarantineManager(new LocalQuarantineRepository(Storage::disk('quarantine')));

        $job->handle($uploader, $users, $metrics, $quarantine);

        $job->assertFailedWith(UploadValidationException::class);
        $this->assertTrue(true);
    }
}
