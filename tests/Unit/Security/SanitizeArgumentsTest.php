<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner;
use App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\YaraScanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SanitizeArgumentsTest extends TestCase
{
    public function test_clamav_sanitizes_and_clamps_arguments(): void
    {
        $scanner = new ClamAvScanner();
        $method = (new ReflectionClass($scanner))->getMethod('sanitizeArguments');
        $method->setAccessible(true);

        $arguments = $method->invoke(
            $scanner,
            ['--timeout', '99', '--max-recursion', '64', '--unknown', '--max-filesize', '999999'],
            1024
        );

        $this->assertSame(['--timeout', '30', '--max-recursion', '32', '--max-filesize', '1024'], $arguments);
    }

    public function test_yara_sanitizes_arguments_and_rejects_unknown_tokens(): void
    {
        $scanner = new YaraScanner();
        $method = (new ReflectionClass($scanner))->getMethod('sanitizeArguments');
        $method->setAccessible(true);

        $arguments = $method->invoke(
            $scanner,
            '--timeout 120 --fail-on-warnings --bad-flag',
            0
        );

        $this->assertSame(['--timeout', '30', '--fail-on-warnings'], $arguments);
    }
}
