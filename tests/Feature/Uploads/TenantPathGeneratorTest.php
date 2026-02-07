<?php

namespace Tests\Feature\Uploads;

use App\Application\Shared\Contracts\TenantContextInterface;
use App\Infrastructure\Uploads\Core\Paths\MediaLibrary\TenantAwarePathGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class TenantPathGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_path_is_tenant_first(): void
    {
        // Arrange: fake tenant context and media in avatar collection
        $context = new class implements TenantContextInterface {
            public function tenantId(): int|string|null { return 99; }
            public function requireTenantId(): int|string { return 99; }
        };
        $this->app->instance(TenantContextInterface::class, $context);
        $generator = $this->app->make(TenantAwarePathGenerator::class);

        $media = Media::query()->create([
            'model_type' => \App\Infrastructure\Models\User::class,
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
            'custom_properties' => ['version' => 1],
            'generated_conversions' => ['thumb' => true],
            'responsive_images' => [],
        ]);

        // Act: generate path
        $path = $generator->getPath($media);

        // Assert: tenant-first path includes tenant/user/avatar segments
        $this->assertStringContainsString('tenants/99/users/7/avatars/', $path);
    }
}
