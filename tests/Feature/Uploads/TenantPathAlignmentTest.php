<?php

namespace Tests\Feature\Uploads;

use App\Application\Shared\Contracts\TenantContextInterface;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Uploads\Core\Paths\MediaLibrary\TenantAwarePathGenerator;
use App\Infrastructure\Uploads\Core\Paths\TenantPathGenerator;
use App\Infrastructure\Uploads\Core\Paths\TenantPathLayout;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class TenantPathAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private TenantContextInterface $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new class implements TenantContextInterface {
            public function tenantId(): int|string|null
            {
                return 42;
            }

            public function requireTenantId(): int|string
            {
                return 42;
            }
        };

        $this->app->instance(TenantContextInterface::class, $this->context);
    }

    public function test_avatar_media_library_matches_upload_generator_base_path(): void
    {
        Str::createUuidsUsing(fn () => Uuid::fromString('11111111-1111-1111-1111-111111111111'));

        try {
            $layout = new TenantPathLayout();
            $paths = new TenantPathGenerator($this->context, $layout);
            $profiles = $this->app->make(UploadProfileRegistry::class);
            $mediaPaths = new TenantAwarePathGenerator($this->context, $paths, $profiles, $layout);

            $media = Media::query()->create([
                'model_type' => \App\Models\User::class,
                'model_id' => 7,
                'uuid' => '11111111-1111-1111-1111-111111111111',
                'collection_name' => 'avatar',
                'name' => 'avatar',
                'file_name' => 'v1.jpg',
                'mime_type' => 'image/jpeg',
                'disk' => 'public',
                'conversions_disk' => 'public',
                'size' => 1,
                'manipulations' => [],
                'custom_properties' => [
                    'version' => 5,
                    'upload_uuid' => 'avatar-upload-uuid',
                    'tenant_id' => 42,
                ],
                'generated_conversions' => ['thumb' => true],
                'responsive_images' => [],
            ]);

            $fullPath = $paths->generate(
                $profiles->get(new UploadProfileId('avatar_image')),
                ownerId: $media->model_id,
                extension: 'jpg',
                version: 5,
                uniqueId: 'avatar-upload-uuid',
            );

            $expectedBase = Str::beforeLast($fullPath, '/') . '/';

            $this->assertSame($expectedBase, $mediaPaths->getPath($media));
            $this->assertSame($expectedBase . 'conversions/', $mediaPaths->getPathForConversions($media));
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function test_gallery_media_library_matches_upload_generator_base_path(): void
    {
        Carbon::setTestNow(Carbon::create(2024, 2, 1, 12));
        Str::createUuidsUsing(fn () => Uuid::fromString('22222222-2222-2222-2222-222222222222'));

        try {
            $layout = new TenantPathLayout();
            $paths = new TenantPathGenerator($this->context, $layout);
            $profiles = $this->app->make(UploadProfileRegistry::class);
            $mediaPaths = new TenantAwarePathGenerator($this->context, $paths, $profiles, $layout);

            $media = Media::query()->create([
                'model_type' => \App\Models\User::class,
                'model_id' => 9,
                'uuid' => '22222222-2222-2222-2222-222222222222',
                'collection_name' => 'gallery',
                'name' => 'gallery-photo',
                'file_name' => 'photo.png',
                'mime_type' => 'image/png',
                'disk' => 'public',
                'conversions_disk' => 'public',
                'size' => 10,
                'manipulations' => [],
                'custom_properties' => [
                    'version' => 3,
                    'upload_uuid' => 'gallery-upload-uuid',
                    'tenant_id' => 42,
                ],
                'generated_conversions' => ['thumb' => true],
                'responsive_images' => [],
            ]);

            $fullPath = $paths->generate(
                $profiles->get(new UploadProfileId('gallery_image')),
                ownerId: $media->model_id,
                extension: 'png',
                version: null,
                uniqueId: null,
            );

            $expectedBase = Str::beforeLast($fullPath, '/') . '/';

            $this->assertSame($expectedBase, $mediaPaths->getPath($media));
            $this->assertSame($expectedBase . 'conversions/', $mediaPaths->getPathForConversions($media));
        } finally {
            Carbon::setTestNow();
            Str::createUuidsNormally();
        }
    }
}
