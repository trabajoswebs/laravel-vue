<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Security\Logging;

use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use Tests\TestCase;

final class MediaLogSanitizerTest extends TestCase
{
    public function test_safe_context_replaces_sensitive_values_with_hashes(): void
    {
        $sanitizer = new MediaLogSanitizer();
        $context = [
            'path' => 'tenants/10/users/5/avatar.jpg',
            'filename' => 'avatar-private.jpg',
            'url' => 'https://s3.test/private/signed?token=abc',
            'token' => 'quarantine-secret-token',
            'headers' => ['Authorization' => 'Bearer abc'],
        ];

        $safe = $sanitizer->safeContext($context);

        self::assertArrayNotHasKey('path', $safe);
        self::assertArrayNotHasKey('filename', $safe);
        self::assertArrayNotHasKey('url', $safe);
        self::assertArrayNotHasKey('token', $safe);
        self::assertArrayNotHasKey('headers', $safe);
        self::assertArrayHasKey('path_hash', $safe);
        self::assertArrayHasKey('name_hash', $safe);
        self::assertArrayHasKey('url_hash', $safe);
        self::assertArrayHasKey('token_hash', $safe);
        self::assertArrayHasKey('headers_hash', $safe);
        self::assertNotSame('tenants/10/users/5/avatar.jpg', $safe['path_hash']);
        self::assertNotSame('avatar-private.jpg', $safe['name_hash']);
    }

    public function test_hash_methods_are_deterministic(): void
    {
        $sanitizer = new MediaLogSanitizer();

        self::assertSame(
            $sanitizer->hashPath('tenants/1/users/2/a.jpg'),
            $sanitizer->hashPath('tenants/1/users/2/a.jpg')
        );
        self::assertSame(
            $sanitizer->hashName('secret-name.jpg'),
            $sanitizer->hashName('secret-name.jpg')
        );
    }

    public function test_safe_exception_normalizes_sensitive_payloads(): void
    {
        $sanitizer = new MediaLogSanitizer();
        $exception = new \RuntimeException('failed at /tmp/private/file.jpg with https://s3.test/signed?x=1');

        $safe = $sanitizer->safeException($exception);

        self::assertSame(\RuntimeException::class, $safe['class']);
        self::assertStringNotContainsString('/tmp/private/file.jpg', $safe['message']);
        self::assertStringNotContainsString('https://s3.test/signed?x=1', $safe['message']);
    }

    public function test_safe_context_hashes_trace_payload(): void
    {
        $sanitizer = new MediaLogSanitizer();

        $safe = $sanitizer->safeContext([
            'trace' => "at /var/www/html/private/file.php\n#0 /var/www/html/secret.php",
        ]);

        self::assertArrayNotHasKey('trace', $safe);
        self::assertArrayHasKey('trace_hash', $safe);
    }
}
