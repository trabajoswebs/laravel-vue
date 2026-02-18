<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Core\Orchestrators;

use App\Support\Contracts\TenantContextInterface;
use App\Application\Uploads\Contracts\UploadRepositoryInterface;
use App\Application\Uploads\Contracts\UploadStorageInterface;
use App\Support\Enums\Uploads\ProcessingMode;
use App\Support\Enums\Uploads\ScanMode;
use App\Support\Enums\Uploads\ServingMode;
use App\Support\Enums\Uploads\UploadKind;
use App\Domain\Uploads\UploadProfile;
use App\Domain\Uploads\UploadProfileId;
use App\Modules\Uploads\Contracts\MediaArtifactCollector;
use App\Modules\Uploads\Contracts\MediaCleanupScheduler;
use App\Modules\Uploads\Contracts\MediaUploader;
use App\Modules\Uploads\Orchestrators\DefaultUploadOrchestrator;
use App\Modules\Uploads\Orchestrators\DocumentUploadGuard;
use App\Modules\Uploads\Orchestrators\MediaProfileResolver;
use App\Modules\Uploads\Paths\TenantPathGenerator;
use App\Modules\Uploads\Services\MediaReplacementService;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Modules\Uploads\Pipeline\Support\PipelineResultMapper;
use App\Modules\Uploads\Pipeline\Support\QuarantineManager;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Infrastructure\Uploads\Profiles\GalleryProfile;
use InvalidArgumentException;
use Tests\TestCase;

final class DefaultUploadOrchestratorProfileResolutionTest extends TestCase
{
    public function test_resolve_media_profile_uses_avatar_profile_for_avatar_image(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveMediaProfile');
        $method->setAccessible(true);

        $profile = $this->makeImageProfile('avatar_image');
        $resolved = $method->invoke($orchestrator, $profile);

        $this->assertInstanceOf(AvatarProfile::class, $resolved);
    }

    public function test_resolve_media_profile_uses_gallery_profile_for_gallery_image(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveMediaProfile');
        $method->setAccessible(true);

        $profile = $this->makeImageProfile('gallery_image');
        $resolved = $method->invoke($orchestrator, $profile);

        $this->assertInstanceOf(GalleryProfile::class, $resolved);
    }

    public function test_resolve_media_profile_rejects_unknown_image_profile(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveMediaProfile');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($orchestrator, $this->makeImageProfile('other_image'));
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
            $this->createMock(UploadStorageInterface::class),
            new MediaReplacementService(
                $this->createMock(MediaUploader::class),
                $this->createMock(MediaArtifactCollector::class),
                $this->createMock(MediaCleanupScheduler::class),
            ),
            new PipelineResultMapper(),
            new MediaProfileResolver(new AvatarProfile(), new GalleryProfile()),
            new DocumentUploadGuard(),
        );
    }

    private function makeImageProfile(string $id): UploadProfile
    {
        return new UploadProfile(
            id: new UploadProfileId($id),
            kind: UploadKind::IMAGE,
            allowedMimes: ['image/jpeg'],
            maxBytes: 2 * 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::IMAGE_PIPELINE,
            servingMode: ServingMode::CONTROLLER_SIGNED,
            disk: 'public',
            pathCategory: 'images',
            requiresOwner: true,
        );
    }
}
