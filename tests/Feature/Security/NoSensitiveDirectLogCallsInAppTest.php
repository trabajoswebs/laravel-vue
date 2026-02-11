<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

class NoSensitiveDirectLogCallsInAppTest extends TestCase
{
    public function test_app_has_no_new_direct_log_debug_info_warning_error_calls(): void
    {
        $root = app_path();
        $baselinePath = base_path('tests/Fixtures/direct-log-calls-baseline.txt');

        $this->assertFileExists($baselinePath, 'Missing baseline file: tests/Fixtures/direct-log-calls-baseline.txt');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $currentViolations = [];
        $pattern = '/\bLog::(?:debug|info|warning|error)\s*\(/';

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (! $fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            $path = $fileInfo->getPathname();
            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $lines = preg_split('/\R/', $contents) ?: [];
            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $currentViolations[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path) . ':' . ($index + 1);
                }
            }
        }

        $currentViolations = array_values(array_unique($currentViolations));
        sort($currentViolations);

        $baseline = @file($baselinePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $baselineViolations = is_array($baseline) ? array_values(array_unique($baseline)) : [];
        sort($baselineViolations);

        $newViolations = array_values(array_diff($currentViolations, $baselineViolations));

        if ($newViolations !== []) {
            throw new ExpectationFailedException(
                "New direct Log calls found (not in baseline):\n" . implode("\n", $newViolations)
            );
        }

        $this->assertTrue(true);
    }
}
