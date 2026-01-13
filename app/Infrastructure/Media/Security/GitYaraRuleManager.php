<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Security;

use App\Infrastructure\Media\Security\Exceptions\InvalidRuleException;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use SplFileInfo;

/**
 * Implementación que lee reglas (checkout git) desde disco local y valida integridad SHA256.
 */
final class GitYaraRuleManager implements YaraRuleManager
{
    public function __construct(
        private readonly string $rulesPath,
        private readonly string $hashFile,
        private readonly ?string $versionFile = null,
    ) {}

    /** @inheritDoc */
    public function getRuleFiles(): array
    {
        if (is_dir($this->rulesPath)) {
            return $this->collectRuleFilesFromDirectory($this->rulesPath);
        }

        if (is_file($this->rulesPath)) {
            return [$this->rulesPath];
        }

        throw new InvalidRuleException(sprintf('YARA rules path [%s] is not accessible.', $this->rulesPath));
    }

    /** @inheritDoc */
    public function getCurrentVersion(): string
    {
        if ($this->versionFile !== null && is_file($this->versionFile)) {
            $version = trim((string) @file_get_contents($this->versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        return substr($this->computeHash(), 0, 12);
    }

    /** @inheritDoc */
    public function validateIntegrity(): void
    {
        $expected = $this->getExpectedHash();
        if ($expected === '') {
            throw new InvalidRuleException('Expected YARA hash is missing.');
        }

        $actual = $this->computeHash();
        if (! hash_equals($expected, $actual)) {
            throw new InvalidRuleException('YARA rule hash mismatch.');
        }

        Log::debug('yara.rules.integrity_ok', [
            'version' => $this->getCurrentVersion(),
        ]);
    }

    /** @inheritDoc */
    public function getExpectedHash(): string
    {
        if (is_file($this->hashFile)) {
            $raw = trim((string) @file_get_contents($this->hashFile));
            if ($raw !== '') {
                return strtolower($raw);
            }
        }

        $fallback = (string) config('image-pipeline.scan.yara.expected_hash', '');
        return strtolower(trim($fallback));
    }

    private function computeHash(): string
    {
        $files = $this->getRuleFiles();
        if ($files === []) {
            throw new InvalidRuleException('No YARA rule files found.');
        }

        sort($files);
        $context = hash_init('sha256');
        foreach ($files as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                throw new InvalidRuleException(sprintf('Rule file [%s] is not readable.', $file));
            }

            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new InvalidRuleException(sprintf('Cannot open YARA file [%s].', $file));
            }

            try {
                hash_update_stream($context, $handle);
            } finally {
                fclose($handle);
            }
        }

        return hash_final($context);
    }

    /**
     * @return list<string>
     */
    private function collectRuleFilesFromDirectory(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (! in_array($extension, ['yar', 'yara', 'yarac'], true)) {
                continue;
            }

            $files[] = $file->getRealPath() ?: $file->getPathname();
        }

        return array_values(array_filter($files));
    }
}
