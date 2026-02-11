<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Media;

use App\Support\Media\TenantAwareUrlGenerator;
use RuntimeException;
use Tests\TestCase;

final class TenantAwareUrlGeneratorTest extends TestCase
{
    public function test_sanitize_relative_path_rejects_traversal(): void
    {
        $generator = app()->make(TenantAwareUrlGenerator::class);
        $method = new \ReflectionMethod($generator, 'sanitizeRelativePath');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $method->invoke($generator, 'tenants/1/users/2/../secrets/file.txt');
    }

    public function test_sanitize_relative_path_rejects_non_tenant_prefix(): void
    {
        $generator = app()->make(TenantAwareUrlGenerator::class);
        $method = new \ReflectionMethod($generator, 'sanitizeRelativePath');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $method->invoke($generator, 'users/2/avatars/file.jpg');
    }

    public function test_sanitize_relative_path_normalizes_segments_and_slashes(): void
    {
        $generator = app()->make(TenantAwareUrlGenerator::class);
        $method = new \ReflectionMethod($generator, 'sanitizeRelativePath');
        $method->setAccessible(true);

        $clean = $method->invoke($generator, '\\tenants\\10\\users\\5\\avatars\\file.jpg');

        $this->assertSame('tenants/10/users/5/avatars/file.jpg', $clean);
    }
}
