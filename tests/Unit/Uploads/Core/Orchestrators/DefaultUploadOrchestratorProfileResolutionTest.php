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
            new MediaReplacementService(
                $this->createMock(MediaUploader::class),
                $this->createMock(MediaArtifactCollector::class),
                $this->createMock(MediaCleanupScheduler::class),
            ),
            new AvatarProfile(),
            new PipelineResultMapper(),
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
