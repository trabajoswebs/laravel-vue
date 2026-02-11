<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

class NoEnvCallsInAppTest extends TestCase
{
    public function test_app_directory_has_no_direct_env_calls(): void
    {
        $root = base_path();
        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }

            if (! preg_match_all('/\benv\s*\(/', $content, $found, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($found[0] as [$match, $offset]) {
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $lineContent = $this->getLine($content, $line);

                if ($this->isCommentLine($lineContent)) {
                    continue;
                }

                $matches[] = sprintf(
                    '%s:%d: %s',
                    str_replace($root . '/', '', $path),
                    $line,
                    $match
                );

                if (count($matches) >= 20) {
                    break 2;
                }
            }
        }

        if ($matches !== []) {
            throw new ExpectationFailedException(
                "Direct env() calls are not allowed in app/:\n" . implode("\n", $matches)
            );
        }

        $this->assertTrue(true);
    }

    private function getLine(string $content, int $lineNumber): string
    {
        $lines = explode("\n", $content);

        return $lines[$lineNumber - 1] ?? '';
    }

    private function isCommentLine(string $line): bool
    {
        $trimmed = ltrim($line);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
            return true;
        }

        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*/')) {
            return true;
        }

        return false;
    }
}
