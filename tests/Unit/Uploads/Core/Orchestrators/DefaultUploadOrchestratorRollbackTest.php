<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Core\Orchestrators;

use App\Support\Contracts\TenantContextInterface;
use App\Application\Uploads\Contracts\UploadRepositoryInterface;
use App\Modules\Uploads\Adapters\LaravelUploadStorage;
use App\Support\Enums\Uploads\ProcessingMode;
use App\Support\Enums\Uploads\ScanMode;
use App\Support\Enums\Uploads\ServingMode;
use App\Support\Enums\Uploads\UploadKind;
use App\Domain\Uploads\UploadProfile;
use App\Domain\Uploads\UploadProfileId;
use App\Application\Uploads\DTO\UploadResult;
use App\Models\User;
use App\Modules\Uploads\Contracts\MediaArtifactCollector;
use App\Modules\Uploads\Contracts\MediaCleanupScheduler;
use App\Modules\Uploads\Contracts\MediaUploader;
use App\Modules\Uploads\Contracts\UploadedMedia;
use App\Infrastructure\Uploads\Core\Orchestrators\DefaultUploadOrchestrator;
use App\Infrastructure\Uploads\Core\Orchestrators\DocumentUploadGuard;
use App\Infrastructure\Uploads\Core\Orchestrators\MediaProfileResolver;
use App\Modules\Uploads\Paths\TenantPathGenerator;
use App\Modules\Uploads\Services\MediaReplacementService;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Uploads\Pipeline\Support\PipelineResultMapper;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Infrastructure\Uploads\Profiles\GalleryProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class DefaultUploadOrchestratorRollbackTest extends TestCase
{
    public function test_document_upload_runs_scan_when_profile_requires_it_even_if_legacy_flag_is_disabled(): void
    {
        config()->set('uploads.virus_scanning.enabled', false);
        Storage::fake('private_docs');

        $tmp = tempnam(sys_get_temp_dir(), 'doc_');
        self::assertIsString($tmp);
        file_put_contents($tmp, "col1,col2\n1,2\n");

        $uploaded = new UploadedFile($tmp, 'import.csv', 'text/csv', null, true);
        $uploadedMedia = new class($uploaded) implements UploadedMedia {
            public function __construct(private UploadedFile $raw) {}
            public function originalName(): string { return $this->raw->getClientOriginalName(); }
            public function mimeType(): ?string { return $this->raw->getMimeType(); }
            public function size(): ?int { return $this->raw->getSize(); }
            public function raw(): mixed { return $this->raw; }
        };

        $profile = new UploadProfile(
            id: new UploadProfileId('doc_import'),
            kind: UploadKind::DOCUMENT,
            allowedMimes: ['text/csv', 'text/plain'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::PRIVATE_SIGNED,
            disk: 'private_docs',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantContext->method('requireTenantId')->willReturn(1);

        $paths = $this->createMock(TenantPathGenerator::class);
        $paths->method('generate')->willReturn('tenants/1/imports/2026/02/import.csv');

        $token = QuarantineToken::fromPath($tmp, 'q/import.bin', 'cid', 'imports');
        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->expects($this->once())
            ->method('putStream')
            ->willReturn($token);
        $quarantineRepository->expects($this->once())
            ->method('delete')
            ->with($token);
        $quarantine = new QuarantineManager($quarantineRepository);

        $scanner = $this->createMock(ScanCoordinatorInterface::class);
        $scanner->expects($this->once())
            ->method('scan')
            ->with(
                $this->isInstanceOf(UploadedFile::class),
                $token->path,
                $this->arrayHasKey('correlation_id')
            );

        $uploads = $this->createMock(UploadRepositoryInterface::class);
        $uploads->expects($this->once())
            ->method('store')
            ->with(
                $this->callback(static fn(UploadResult $result): bool => $result->status === 'stored'),
                $profile,
                $this->isInstanceOf(User::class),
                null
            );

        $mediaReplacement = new MediaReplacementService(
            $this->createMock(MediaUploader::class),
            $this->createMock(MediaArtifactCollector::class),
            $this->createMock(MediaCleanupScheduler::class),
        );

        $orchestrator = new DefaultUploadOrchestrator(
            $tenantContext,
            $paths,
            $quarantine,
            $scanner,
            $uploads,
            new LaravelUploadStorage(),
            $mediaReplacement,
            new PipelineResultMapper(),
            new MediaProfileResolver(new AvatarProfile(), new GalleryProfile()),
            new DocumentUploadGuard(),
        );

        $actor = new User();
        $actor->forceFill(['id' => 7, 'current_tenant_id' => 1]);

        try {
            $result = $orchestrator->upload($profile, $actor, $uploadedMedia, null, 'cid');
            $this->assertSame('stored', $result->status);
            $this->assertSame('private_docs', $result->disk);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_document_upload_rolls_back_stored_file_when_metadata_persistence_fails(): void
    {
        Storage::fake('private_docs');

        $tmp = tempnam(sys_get_temp_dir(), 'doc_');
        self::assertIsString($tmp);
        file_put_contents($tmp, "hello,csv\n1,2\n");

        $uploaded = new UploadedFile($tmp, 'import.csv', 'text/csv', null, true);
        $uploadedMedia = new class($uploaded) implements UploadedMedia {
            public function __construct(private UploadedFile $raw) {}
            public function originalName(): string { return $this->raw->getClientOriginalName(); }
            public function mimeType(): ?string { return $this->raw->getMimeType(); }
            public function size(): ?int { return $this->raw->getSize(); }
            public function raw(): mixed { return $this->raw; }
        };

        $profile = new UploadProfile(
            id: new UploadProfileId('doc_import'),
            kind: UploadKind::DOCUMENT,
            allowedMimes: ['text/csv', 'text/plain'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::DISABLED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::PRIVATE_SIGNED,
            disk: 'private_docs',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        $tenantContext = $this->createMock(TenantContextInterface::class);
        $tenantContext->method('requireTenantId')->willReturn(1);

        $paths = $this->createMock(TenantPathGenerator::class);
        $paths->method('generate')->willReturn('tenants/1/imports/2026/02/import.csv');

        $token = QuarantineToken::fromPath($tmp, 'q/import.bin', 'cid', 'imports');
        $quarantineRepository = $this->createMock(QuarantineRepository::class);
        $quarantineRepository->expects($this->once())
            ->method('putStream')
            ->willReturn($token);
        $quarantineRepository->expects($this->once())
            ->method('delete')
            ->with($token);
        $quarantine = new QuarantineManager($quarantineRepository);

        $uploads = $this->createMock(UploadRepositoryInterface::class);
        $uploads->method('store')->willThrowException(new RuntimeException('db-failed'));

        $mediaReplacement = new MediaReplacementService(
            $this->createMock(MediaUploader::class),
            $this->createMock(MediaArtifactCollector::class),
            $this->createMock(MediaCleanupScheduler::class),
        );

        $orchestrator = new DefaultUploadOrchestrator(
            $tenantContext,
            $paths,
            $quarantine,
            $this->createMock(ScanCoordinatorInterface::class),
            $uploads,
            new LaravelUploadStorage(),
            $mediaReplacement,
            new PipelineResultMapper(),
            new MediaProfileResolver(new AvatarProfile(), new GalleryProfile()),
            new DocumentUploadGuard(),
        );

        $actor = new User();
        $actor->forceFill(['id' => 7, 'current_tenant_id' => 1]);

        try {
            $this->expectException(RuntimeException::class);
            $orchestrator->upload($profile, $actor, $uploadedMedia, null, 'cid');
        } finally {
            self::assertFalse(Storage::disk('private_docs')->exists('tenants/1/imports/2026/02/import.csv'));
            @unlink($tmp);
        }
    }
}
