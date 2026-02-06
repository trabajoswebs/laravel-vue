<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

class NoPublicStorageUrlTest extends TestCase
{
    /**
     * Static scan to prevent reintroducing /storage public URLs or Storage::url usage.
     *
     * Scans app/ and resources/ for dangerous patterns. Excludes config/, storage/, vendor/, tests/, docs.
     */
    public function test_codebase_has_no_public_storage_links(): void
    {
        $root = base_path();
        $scanDirs = [
            app_path(),
            resource_path(),
        ];

        $patterns = '/'
            . 'Storage::url\\('                     // Storage::url()
            . '|Storage::disk\\([^)]*\\)->url\\('   // Storage::disk(...)->url()
            . '|asset\\([\'"]\\/?storage'           // asset('storage...') or asset('/storage...')
            . '|url\\([\'"]\\/storage'              // url('/storage...')
            . '|[\'"]\\/storage(?:\\b|\\/)'         // "/storage" or "/storage/..."
            . '/i';

        $matches = [];

        foreach ($scanDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                // Skip disallowed extensions early
                $ext = strtolower($file->getExtension());
                $isBlade = str_ends_with($path, '.blade.php');
                if (! in_array($ext, ['php', 'ts', 'js', 'vue'], true) && ! $isBlade) {
                    continue;
                }

                $content = @file_get_contents($path);
                if ($content === false) {
                    continue;
                }

                $this->collectPatternMatches($patterns, $content, $path, $root, $matches);

                // Detect direct use of Spatie UrlGenerator methods outside the tenant-aware generator.
                // Limit the scan to files that actually reference the UrlGenerator class to avoid false positives
                // in regular media->getUrl() usages.
                if (
                    preg_match('/Spatie\\\\MediaLibrary\\\\Support\\\\UrlGenerator/', $content)
                    && ! str_contains($path, 'Support/Media/TenantAwareUrlGenerator.php')
                ) {
                    $this->collectPatternMatches('/->\\s*get(?:Temporary)?Url\\s*\\(/', $content, $path, $root, $matches);
                }
            }
        }

        if ($matches !== []) {
            $message = "Found public storage references:\n" . implode("\n", $matches);
            throw new ExpectationFailedException($message);
        }

        $this->assertTrue(true); // Explicit assertion for PHPUnit
    }

    private function collectPatternMatches(string $pattern, string $content, string $path, string $root, array &$matches): void
    {
        if (! preg_match_all($pattern, $content, $found, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($found[0] as [$match, $offset]) {
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
            $lineContent = $this->getLine($content, $line);

            if ($this->isCommentLine($lineContent)) {
                continue; // Ignore matches in comment-only lines
            }

            if (str_contains($lineContent, 'NO-USAR-STORAGE')) { // explicit whitelist
                continue;
            }

            $matches[] = sprintf(
                '%s:%d: %s',
                str_replace($root . '/', '', $path),
                $line,
                $match
            );

            if (count($matches) >= 20) {
                break; // limit noise
            }
        }
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

        // Single-line comments
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
            return true;
        }

        // Docblock or block comment line prefix
        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*/')) {
            return true;
        }

        return false;
    }
}
