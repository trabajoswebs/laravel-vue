<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline;

use App\Support\Contracts\AsyncJobDispatcherInterface;
use App\Models\User;
use App\Support\Security\Exceptions\AntivirusException;
use App\Modules\Uploads\Contracts\FileConstraints;
use App\Modules\Uploads\Contracts\MediaProfile;
use App\Modules\Uploads\Pipeline\Contracts\UploadPipeline;
use App\Modules\Uploads\Pipeline\DefaultUploadService;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
use App\Modules\Uploads\Pipeline\Exceptions\UploadException;
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Modules\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use App\Modules\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Support\ImageUploadReporter;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class DefaultUploadServiceCleanupTest extends TestCase
{
    public function test_process_quarantined_deletes_quarantine_when_scan_fails_before_artifact(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qf_');
        self::assertIsString($path);
        file_put_contents($path, 'payload');

        $token = QuarantineToken::fromPath($path, 'q/test.bin', 'cid', 'avatar');
        $file = new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);

        $constraints = $this->createMock(FileConstraints::class);
        $constraints->method('assertValidUpload')->willReturnCallback(static function (): void {});

        $profile = $this->createMock(MediaProfile::class);
        $profile->method('collection')->willReturn('avatar');
        $profile->method('usesAntivirus')->willReturn(true);
        $profile->method('fileConstraints')->willReturn($constraints);

        $owner = new User();
        $owner->forceFill(['id' => 7]);

        $pipeline = $this->createMock(UploadPipeline::class);
        $scanner = $this->createMock(ScanCoordinatorInterface::class);
        $scanner->method('scan')->willThrowException(new UploadValidationException('scan-failed'));

        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->method('transition')->willReturnCallback(static function (): void {});
        $quarantineRepository->expects($this->once())
            ->method('delete')
            ->with($token);
        $quarantine = new QuarantineManager($quarantineRepository);
        $reporter = new ImageUploadReporter(
            $this->createMock(ExceptionHandler::class),
            new MediaLogSanitizer(),
        );
        $mediaLogger = new MediaSecurityLogger(new MediaLogSanitizer());
        $uploadLogger = new UploadSecurityLogger($mediaLogger);

        $service = new DefaultUploadService(
            $pipeline,
            $quarantine,
            $scanner,
            $reporter,
            $uploadLogger,
            $mediaLogger,
            $this->createMock(AsyncJobDispatcherInterface::class),
        );

        try {
            $this->expectException(UploadValidationException::class);
            $service->processQuarantined($owner, $file, $token, $profile, 'cid');
        } finally {
            @unlink($path);
        }
    }

    public function test_process_quarantined_preserves_quarantine_on_retryable_antivirus_failure_when_enabled(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qf_');
        self::assertIsString($path);
        file_put_contents($path, 'payload');

        $token = QuarantineToken::fromPath($path, 'q/test.bin', 'cid', 'avatar');
        $file = new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);

        $constraints = $this->createMock(FileConstraints::class);
        $constraints->method('assertValidUpload')->willReturnCallback(static function (): void {});

        $profile = $this->createMock(MediaProfile::class);
        $profile->method('collection')->willReturn('avatar');
        $profile->method('usesAntivirus')->willReturn(true);
        $profile->method('fileConstraints')->willReturn($constraints);

        $owner = new User();
        $owner->forceFill(['id' => 7]);

        $pipeline = $this->createMock(UploadPipeline::class);
        $scanner = $this->createMock(ScanCoordinatorInterface::class);
        $scanner->method('scan')->willThrowException(
            new UploadValidationException(
                'scan-failed',
                new AntivirusException('clamav', 'timeout')
            )
        );

        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->method('transition')->willReturnCallback(static function (): void {});
        $quarantineRepository->expects($this->never())
            ->method('delete');
        $quarantine = new QuarantineManager($quarantineRepository);
        $reporter = new ImageUploadReporter(
            $this->createMock(ExceptionHandler::class),
            new MediaLogSanitizer(),
        );
        $mediaLogger = new MediaSecurityLogger(new MediaLogSanitizer());
        $uploadLogger = new UploadSecurityLogger($mediaLogger);

        $service = new DefaultUploadService(
            $pipeline,
            $quarantine,
            $scanner,
            $reporter,
            $uploadLogger,
            $mediaLogger,
            $this->createMock(AsyncJobDispatcherInterface::class),
        );

        try {
            $this->expectException(UploadValidationException::class);
            $service->processQuarantined($owner, $file, $token, $profile, 'cid', true);
        } finally {
            @unlink($path);
        }
    }

    public function test_upload_cleans_quarantine_if_queue_dispatch_fails(): void
    {
        $previousQueueDefault = config('queue.default');
        $previousAsyncConnection = config('queue.connections.async-test');
        config()->set('queue.default', 'async-test');
        config()->set('queue.connections.async-test.driver', 'redis');

        $path = tempnam(sys_get_temp_dir(), 'qf_');
        self::assertIsString($path);
        file_put_contents($path, 'payload');

        $uploaded = new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);
        $uploadedMedia = new class($uploaded) implements \App\Modules\Uploads\Contracts\UploadedMedia {
            public function __construct(private UploadedFile $raw) {}
            public function originalName(): string { return $this->raw->getClientOriginalName(); }
            public function mimeType(): ?string { return $this->raw->getMimeType(); }
            public function size(): ?int { return $this->raw->getSize(); }
            public function raw(): mixed { return $this->raw; }
        };

        $constraints = $this->createMock(FileConstraints::class);
        $constraints->method('assertValidUpload')->willReturnCallback(static function (): void {});

        $profile = $this->createMock(MediaProfile::class);
        $profile->method('collection')->willReturn('avatar');
        $profile->method('fileConstraints')->willReturn($constraints);

        $owner = new User();
        $owner->forceFill(['id' => 7]);

        $token = QuarantineToken::fromPath($path, 'q/test.bin', 'cid', 'avatar');
        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->expects($this->once())
            ->method('putStream')
            ->willReturn($token);
        $quarantineRepository->expects($this->once())
            ->method('delete')
            ->with($token);

        $jobs = $this->createMock(AsyncJobDispatcherInterface::class);
        $jobs->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('queue-down'));

        $service = new DefaultUploadService(
            $this->createMock(UploadPipeline::class),
            new QuarantineManager($quarantineRepository),
            $this->createMock(ScanCoordinatorInterface::class),
            new ImageUploadReporter($this->createMock(ExceptionHandler::class), new MediaLogSanitizer()),
            new UploadSecurityLogger(new MediaSecurityLogger(new MediaLogSanitizer())),
            new MediaSecurityLogger(new MediaLogSanitizer()),
            $jobs,
        );

        try {
            $this->expectException(UploadException::class);
            $service->upload($owner, $uploadedMedia, $profile, 'cid');
        } finally {
            config()->set('queue.default', $previousQueueDefault);
            config()->set('queue.connections.async-test', $previousAsyncConnection);
            @unlink($path);
        }
    }

    public function test_upload_does_not_wrap_processing_exceptions_when_queue_driver_is_sync(): void
    {
        $previousQueueDefault = config('queue.default');
        config()->set('queue.default', 'sync');

        $path = tempnam(sys_get_temp_dir(), 'qf_');
        self::assertIsString($path);
        file_put_contents($path, 'payload');

        $uploaded = new UploadedFile($path, 'avatar.jpg', 'image/jpeg', null, true);
        $uploadedMedia = new class($uploaded) implements \App\Modules\Uploads\Contracts\UploadedMedia {
            public function __construct(private UploadedFile $raw) {}
            public function originalName(): string { return $this->raw->getClientOriginalName(); }
            public function mimeType(): ?string { return $this->raw->getMimeType(); }
            public function size(): ?int { return $this->raw->getSize(); }
            public function raw(): mixed { return $this->raw; }
        };

        $constraints = $this->createMock(FileConstraints::class);
        $constraints->method('assertValidUpload')->willReturnCallback(static function (): void {});

        $profile = $this->createMock(MediaProfile::class);
        $profile->method('collection')->willReturn('avatar');
        $profile->method('fileConstraints')->willReturn($constraints);

        $owner = new User();
        $owner->forceFill(['id' => 7]);

        $token = QuarantineToken::fromPath($path, 'q/test.bin', 'cid', 'avatar');
        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->expects($this->once())
            ->method('putStream')
            ->willReturn($token);
        $quarantineRepository->expects($this->never())
            ->method('delete');

        $jobs = $this->createMock(AsyncJobDispatcherInterface::class);
        $jobs->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new UploadValidationException('sync-processing-failed'));

        $service = new DefaultUploadService(
            $this->createMock(UploadPipeline::class),
            new QuarantineManager($quarantineRepository),
            $this->createMock(ScanCoordinatorInterface::class),
            new ImageUploadReporter($this->createMock(ExceptionHandler::class), new MediaLogSanitizer()),
            new UploadSecurityLogger(new MediaSecurityLogger(new MediaLogSanitizer())),
            new MediaSecurityLogger(new MediaLogSanitizer()),
            $jobs,
        );

        try {
            $this->expectException(UploadValidationException::class);
            $service->upload($owner, $uploadedMedia, $profile, 'cid');
        } finally {
            config()->set('queue.default', $previousQueueDefault);
            @unlink($path);
        }
    }

    public function test_quarantine_is_preserved_on_retryable_attach_failure_and_retry_can_succeed(): void
    {
        $quarantinePath = tempnam(sys_get_temp_dir(), 'qf_');
        self::assertIsString($quarantinePath);
        file_put_contents($quarantinePath, 'payload');

        $artifactPathFirstAttempt = tempnam(sys_get_temp_dir(), 'art_');
        self::assertIsString($artifactPathFirstAttempt);
        file_put_contents($artifactPathFirstAttempt, 'artifact-first');

        $artifactPathSecondAttempt = tempnam(sys_get_temp_dir(), 'art_');
        self::assertIsString($artifactPathSecondAttempt);
        file_put_contents($artifactPathSecondAttempt, 'artifact-second');

        $token = QuarantineToken::fromPath($quarantinePath, 'q/test.bin', 'cid', 'avatar');
        $currentState = QuarantineState::PENDING;
        $deleteCalls = 0;

        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->method('getState')
            ->willReturnCallback(static function () use (&$currentState): QuarantineState {
                return $currentState;
            });
        $quarantineRepository->method('transition')
            ->willReturnCallback(static function (
                QuarantineToken $transitionToken,
                QuarantineState $from,
                QuarantineState $to
            ) use (&$currentState, $token): void {
                self::assertSame($token->identifier(), $transitionToken->identifier());
                self::assertSame($currentState, $from);
                $currentState = $to;
            });
        $quarantineRepository->method('delete')
            ->willReturnCallback(static function (QuarantineToken $deleteToken) use (&$deleteCalls, $token, $quarantinePath): void {
                self::assertSame($token->identifier(), $deleteToken->identifier());
                $deleteCalls++;
                if (is_file($quarantinePath)) {
                    @unlink($quarantinePath);
                }
            });

        $metadataFirst = new \App\Modules\Uploads\Pipeline\Contracts\UploadMetadata(
            mime: 'image/jpeg',
            extension: 'jpg',
            hash: 'hash-first',
            dimensions: null,
            originalFilename: 'avatar.jpg',
        );
        $metadataSecond = new \App\Modules\Uploads\Pipeline\Contracts\UploadMetadata(
            mime: 'image/jpeg',
            extension: 'jpg',
            hash: 'hash-second',
            dimensions: null,
            originalFilename: 'avatar.jpg',
        );

        $pipeline = $this->createMock(UploadPipeline::class);
        $pipeline->expects($this->exactly(2))
            ->method('process')
            ->willReturnOnConsecutiveCalls(
                new InternalPipelineResult($artifactPathFirstAttempt, (int) filesize($artifactPathFirstAttempt), $metadataFirst),
                new InternalPipelineResult($artifactPathSecondAttempt, (int) filesize($artifactPathSecondAttempt), $metadataSecond),
            );

        $owner = $this->getMockBuilder(User::class)
            ->onlyMethods(['getKey', 'addMedia'])
            ->getMock();
        $owner->method('getKey')->willReturn(7);

        $failingAdder = $this->createMock(FileAdder::class);
        $failingAdder->method('usingFileName')->willReturnSelf();
        $failingAdder->method('addCustomHeaders')->willReturnSelf();
        $failingAdder->method('withCustomProperties')->willReturnSelf();
        $failingAdder->method('toMediaCollection')->willThrowException(new \RuntimeException('attach-transient'));

        $successfulAdder = $this->createMock(FileAdder::class);
        $successfulAdder->method('usingFileName')->willReturnSelf();
        $successfulAdder->method('addCustomHeaders')->willReturnSelf();
        $successfulAdder->method('withCustomProperties')->willReturnSelf();
        $persistedMedia = new Media();
        $persistedMedia->forceFill(['id' => 1234]);
        $successfulAdder->method('toMediaCollection')->willReturn($persistedMedia);

        $owner->expects($this->exactly(2))
            ->method('addMedia')
            ->willReturnOnConsecutiveCalls($failingAdder, $successfulAdder);

        $constraints = $this->createMock(FileConstraints::class);
        $constraints->method('assertValidUpload')->willReturnCallback(static function (): void {});

        $profile = $this->createMock(MediaProfile::class);
        $profile->method('collection')->willReturn('avatar');
        $profile->method('usesAntivirus')->willReturn(false);
        $profile->method('isSingleFile')->willReturn(false);
        $profile->method('disk')->willReturn(null);
        $profile->method('fileConstraints')->willReturn($constraints);

        $service = new DefaultUploadService(
            $pipeline,
            new QuarantineManager($quarantineRepository),
            $this->createMock(ScanCoordinatorInterface::class),
            new ImageUploadReporter($this->createMock(ExceptionHandler::class), new MediaLogSanitizer()),
            new UploadSecurityLogger(new MediaSecurityLogger(new MediaLogSanitizer())),
            new MediaSecurityLogger(new MediaLogSanitizer()),
            $this->createMock(AsyncJobDispatcherInterface::class),
        );

        try {
            $firstAttemptFile = new UploadedFile($quarantinePath, 'avatar.jpg', 'image/jpeg', null, true);
            try {
                $service->processQuarantined($owner, $firstAttemptFile, $token, $profile, 'cid', true, 1);
                $this->fail('Expected retryable attach failure on first attempt.');
            } catch (UploadException) {
                // Expected: first attempt should fail and preserve quarantine.
            }

            self::assertTrue(is_file($quarantinePath), 'Quarantine file must remain for retry.');
            self::assertSame(0, $deleteCalls);
            self::assertSame(QuarantineState::CLEAN, $currentState);
            self::assertFalse(is_file($artifactPathFirstAttempt));

            $secondAttemptFile = new UploadedFile($quarantinePath, 'avatar.jpg', 'image/jpeg', null, true);
            $result = $service->processQuarantined($owner, $secondAttemptFile, $token, $profile, 'cid', true, 2);

            self::assertSame(1, $deleteCalls);
            self::assertFalse(is_file($quarantinePath));
            self::assertSame(QuarantineState::PROMOTED, $currentState);
            self::assertFalse(is_file($artifactPathSecondAttempt));
            self::assertSame(1234, $result->raw()->getKey());
        } finally {
            @unlink($quarantinePath);
            @unlink($artifactPathFirstAttempt);
            @unlink($artifactPathSecondAttempt);
        }
    }
}
