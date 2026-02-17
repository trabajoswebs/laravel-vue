<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline;

use App\Support\Contracts\AsyncJobDispatcherInterface;
use App\Models\User;
use App\Modules\Uploads\Contracts\MediaOwner;
use App\Modules\Uploads\Pipeline\Contracts\UploadPipeline;
use App\Infrastructure\Uploads\Pipeline\DefaultUploadService;
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Support\ImageUploadReporter;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Tests\TestCase;

final class DefaultUploadServiceOwnerKeyTest extends TestCase
{
    public function test_resolve_owner_key_accepts_eloquent_owner(): void
    {
        $service = $this->makeService();
        $method = new \ReflectionMethod($service, 'resolveOwnerKey');
        $method->setAccessible(true);

        $owner = new User();
        $owner->forceFill(['id' => 77]);

        $this->assertSame(77, $method->invoke($service, $owner));
    }

    public function test_resolve_owner_key_rejects_owner_without_get_key(): void
    {
        $service = $this->makeService();
        $method = new \ReflectionMethod($service, 'resolveOwnerKey');
        $method->setAccessible(true);

        $owner = $this->createMock(MediaOwner::class);

        $this->expectException(UploadValidationException::class);
        $method->invoke($service, $owner);
    }

    private function makeService(): DefaultUploadService
    {
        $mediaLogger = new MediaSecurityLogger(new MediaLogSanitizer());
        $uploadLogger = new UploadSecurityLogger($mediaLogger);

        return new DefaultUploadService(
            $this->createMock(UploadPipeline::class),
            new QuarantineManager($this->createMock(QuarantineRepository::class)),
            $this->createMock(ScanCoordinatorInterface::class),
            new ImageUploadReporter(
                $this->createMock(ExceptionHandler::class),
                new MediaLogSanitizer(),
            ),
            $uploadLogger,
            $mediaLogger,
            $this->createMock(AsyncJobDispatcherInterface::class),
        );
    }
}
