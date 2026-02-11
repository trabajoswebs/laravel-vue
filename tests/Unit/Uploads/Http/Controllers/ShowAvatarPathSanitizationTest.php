<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Http\Controllers;

use App\Infrastructure\Uploads\Http\Controllers\Media\ShowAvatar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class ShowAvatarPathSanitizationTest extends TestCase
{
    public function test_sanitize_path_rejects_legacy_avatar_path_without_tenant_prefix(): void
    {
        $controller = app(ShowAvatar::class);
        $method = new \ReflectionMethod($controller, 'sanitizePath');
        $method->setAccessible(true);

        $this->expectException(NotFoundHttpException::class);
        $method->invoke($controller, 'users/5/avatar/file.jpg', 10, 5);
    }

    public function test_sanitize_path_accepts_tenant_first_avatar_path(): void
    {
        $controller = app(ShowAvatar::class);
        $method = new \ReflectionMethod($controller, 'sanitizePath');
        $method->setAccessible(true);

        $clean = $method->invoke($controller, 'tenants/10/users/5/avatars/file.jpg', 10, 5);

        $this->assertSame('tenants/10/users/5/avatars/file.jpg', $clean);
    }

    public function test_sanitize_path_rejects_encoded_traversal_segment(): void
    {
        $controller = app(ShowAvatar::class);
        $method = new \ReflectionMethod($controller, 'sanitizePath');
        $method->setAccessible(true);

        $this->expectException(NotFoundHttpException::class);
        $method->invoke($controller, 'tenants/10/users/5/avatars/%2e%2e/secret.jpg', 10, 5);
    }

    public function test_sanitize_path_rejects_encoded_slash_inside_segment(): void
    {
        $controller = app(ShowAvatar::class);
        $method = new \ReflectionMethod($controller, 'sanitizePath');
        $method->setAccessible(true);

        $this->expectException(NotFoundHttpException::class);
        $method->invoke($controller, 'tenants/10/users/5/avatars/safe%2fsecret.jpg', 10, 5);
    }
}
