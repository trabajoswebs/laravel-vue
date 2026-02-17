<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Optimizer;

use App\Modules\Uploads\Pipeline\Optimizer\OptimizerService;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

final class OptimizerServiceTenantPathTest extends TestCase
{
    public function test_assert_tenant_scoped_relative_path_rejects_non_tenant_first_paths(): void
    {
        $service = new OptimizerService();
        $media = new Media();
        $media->setCustomProperty('tenant_id', 1);

        $method = new \ReflectionMethod($service, 'assertTenantScopedRelativePath');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path_not_tenant_first');

        $method->invoke($service, 'users/5/avatars/file.jpg', $media);
    }

    public function test_assert_tenant_scoped_relative_path_rejects_tenant_mismatch(): void
    {
        $service = new OptimizerService();
        $media = new Media();
        $media->setCustomProperty('tenant_id', 1);

        $method = new \ReflectionMethod($service, 'assertTenantScopedRelativePath');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('path_tenant_mismatch');

        $method->invoke($service, 'tenants/2/users/5/avatars/file.jpg', $media);
    }

    public function test_assert_tenant_scoped_relative_path_accepts_valid_tenant_first_path(): void
    {
        $service = new OptimizerService();
        $media = new Media();
        $media->setCustomProperty('tenant_id', 1);

        $method = new \ReflectionMethod($service, 'assertTenantScopedRelativePath');
        $method->setAccessible(true);

        $method->invoke($service, 'tenants/1/users/5/avatars/file.jpg', $media);

        $this->assertTrue(true);
    }
}
