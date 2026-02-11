<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Core\Orchestrators;

use App\Application\Shared\Contracts\TenantContextInterface;
use App\Application\Uploads\Contracts\UploadRepositoryInterface;
use App\Domain\Uploads\ProcessingMode;
use App\Domain\Uploads\ScanMode;
use App\Domain\Uploads\ServingMode;
use App\Domain\Uploads\UploadKind;
use App\Domain\Uploads\UploadProfile;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Uploads\Core\Contracts\MediaArtifactCollector;
use App\Infrastructure\Uploads\Core\Contracts\MediaCleanupScheduler;
use App\Infrastructure\Uploads\Core\Contracts\MediaUploader;
use App\Infrastructure\Uploads\Core\Orchestrators\DefaultUploadOrchestrator;
use App\Infrastructure\Uploads\Core\Paths\TenantPathGenerator;
use App\Infrastructure\Uploads\Core\Services\MediaReplacementService;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Uploads\Pipeline\Support\PipelineResultMapper;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class DefaultUploadOrchestratorSecurityHardeningTest extends TestCase
{
    public function test_validate_document_does_not_trust_client_mime_for_imports(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_');
        self::assertIsString($path);
        file_put_contents($path, "\x00\x01\x02\x03\x04\x05\x06\x07");

        $uploaded = new UploadedFile($path, 'evil.csv', 'text/plain', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::DOCUMENT,
            allowedMimes: ['text/csv', 'text/plain'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::CONTROLLER_SIGNED,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_rejects_secret_payload_without_pkcs12_signature(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_');
        self::assertIsString($path);
        file_put_contents($path, "\x01\x02\x03\x04" . random_bytes(64));

        $uploaded = new UploadedFile($path, 'cert.p12', 'application/octet-stream', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_accepts_secret_payload_with_pkcs12_like_signature(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_ok_');
        self::assertIsString($path);
        file_put_contents($path, "\x30\x82\x04\x00" . random_bytes(64));

        $uploaded = new UploadedFile($path, 'cert.p12', 'application/octet-stream', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $method->invoke($orchestrator, $profile, $uploaded);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_rejects_secret_payload_with_weak_der_prefix(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_weak_');
        self::assertIsString($path);
        file_put_contents($path, "\x30\x01\x02\x03" . random_bytes(64));

        $uploaded = new UploadedFile($path, 'cert.p12', 'application/octet-stream', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_resolve_tenant_first_media_path_rejects_non_tenant_paths(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveTenantFirstMediaPath');
        $method->setAccessible(true);

        $media = new class {
            public function getPathRelativeToRoot(): string
            {
                return 'users/5/avatars/file.jpg';
            }
        };

        $this->expectException(RuntimeException::class);
        $method->invoke($orchestrator, $media, 1);
    }

    public function test_resolve_tenant_first_media_path_accepts_tenant_prefixed_paths(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveTenantFirstMediaPath');
        $method->setAccessible(true);

        $media = new class {
            public function getPathRelativeToRoot(): string
            {
                return 'tenants/1/users/5/avatars/file.jpg';
            }
        };

        $clean = $method->invoke($orchestrator, $media, 1);
        $this->assertSame('tenants/1/users/5/avatars/file.jpg', $clean);
    }

    private function makeOrchestrator(): DefaultUploadOrchestrator
    {
        $quarantine = new QuarantineManager($this->createMock(QuarantineRepository::class));

        return new DefaultUploadOrchestrator(
            $this->createMock(TenantContextInterface::class),
            $this->createMock(TenantPathGenerator::class),
            $quarantine,
            $this->createMock(ScanCoordinatorInterface::class),
            $this->createMock(UploadRepositoryInterface::class),
            new MediaReplacementService(
                $this->createMock(MediaUploader::class),
                $this->createMock(MediaArtifactCollector::class),
                $this->createMock(MediaCleanupScheduler::class),
            ),
            new AvatarProfile(),
            new PipelineResultMapper(),
        );
    }
}
