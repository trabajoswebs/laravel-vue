<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Support;

use App\Modules\Uploads\Pipeline\Support\MediaCleanupArtifactsBuilder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Tests\TestCase;

final class MediaCleanupArtifactsBuilderTest extends TestCase
{
    public function test_for_media_builds_original_and_conversion_artifacts(): void
    {
        $pathGenerator = $this->createMock(PathGenerator::class);
        $pathGenerator->method('getPath')->willReturn('tenants/1/users/5/avatars/uuid-1/');
        $pathGenerator->method('getPathForConversions')->willReturn('tenants/1/users/5/avatars/uuid-1/conversions/');
        $pathGenerator->method('getPathForResponsiveImages')->willReturn('tenants/1/users/5/avatars/uuid-1/responsive-images/');

        $media = new Media();
        $media->forceFill([
            'id' => 33,
            'disk' => 'public',
            'conversions_disk' => 'public',
        ]);

        $builder = new MediaCleanupArtifactsBuilder($pathGenerator);
        $artifacts = $builder->forMedia($media);

        $this->assertArrayHasKey('public', $artifacts);
        $this->assertCount(3, $artifacts['public']);
        $this->assertSame('tenants/1/users/5/avatars/uuid-1', $artifacts['public'][0]['dir']);
        $this->assertSame('33', $artifacts['public'][0]['mediaId']);
    }
}
