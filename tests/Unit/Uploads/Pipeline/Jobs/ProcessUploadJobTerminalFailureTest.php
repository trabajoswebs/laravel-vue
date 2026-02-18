<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Jobs;

use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Jobs\ProcessUploadJob;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Modules\Uploads\Pipeline\Support\QuarantineManager;
use Tests\TestCase;

final class ProcessUploadJobTerminalFailureTest extends TestCase
{
    public function test_failed_transitions_pending_token_to_failed_and_deletes_artifact(): void
    {
        $token = QuarantineToken::fromPath('/tmp/fake.bin', 'q/a.bin', 'cid', 'avatar');
        $job = new ProcessUploadJob($token, '7', \App\Modules\Uploads\Profiles\AvatarProfile::class, 'cid-terminal');

        $quarantineRepo = $this->getMockBuilder(QuarantineRepository::class)
            ->getMock();

        $quarantineRepo->expects($this->once())
            ->method('getState')
            ->with($token)
            ->willReturn(QuarantineState::PENDING);

        $quarantineRepo->expects($this->once())
            ->method('transition')
            ->with(
                $token,
                QuarantineState::PENDING,
                QuarantineState::FAILED,
                $this->callback(static function (array $context): bool {
                    $metadata = $context['metadata'] ?? null;
                    if (!is_array($metadata)) {
                        return false;
                    }

                    return ($metadata['status'] ?? null) === 'terminal_failed'
                        && ($metadata['attempts'] ?? null) === 1
                        && str_contains((string) ($metadata['reason'] ?? ''), 'retry exhausted');
                })
            );

        $quarantineRepo->expects($this->once())
            ->method('delete')
            ->with($token);

        $quarantine = new QuarantineManager($quarantineRepo);

        $this->app->instance(QuarantineManager::class, $quarantine);

        $job->failed(new UploadValidationException('retry exhausted'));
    }

    public function test_failed_does_not_transition_when_token_is_already_terminal_but_still_deletes(): void
    {
        $token = QuarantineToken::fromPath('/tmp/fake.bin', 'q/a.bin', 'cid', 'avatar');
        $job = new ProcessUploadJob($token, '7', \App\Modules\Uploads\Profiles\AvatarProfile::class, 'cid-terminal-existing');

        $quarantineRepo = $this->getMockBuilder(QuarantineRepository::class)
            ->getMock();

        $quarantineRepo->expects($this->once())
            ->method('getState')
            ->with($token)
            ->willReturn(QuarantineState::FAILED);

        $quarantineRepo->expects($this->never())
            ->method('transition');

        $quarantineRepo->expects($this->once())
            ->method('delete')
            ->with($token);

        $quarantine = new QuarantineManager($quarantineRepo);
        $this->app->instance(QuarantineManager::class, $quarantine);

        $job->failed(new UploadValidationException('already terminal'));
    }
}
