<?php

declare(strict_types=1);

namespace App\Services\Optimizer\Adapters;

use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChain;

/**
 * Ejecuta optimizaciones locales aplicando validaciones de tamaño y MIME.
 */
final class LocalOptimizationAdapter
{
    /**
     * @param array<int,string> $allowedMimes
     */
    public function __construct(
        private readonly OptimizerChain $optimizer,
        private readonly int $maxFileSize,
        private readonly array $allowedMimes,
    ) {}

    /**
     * @return array{bytes_before:int,bytes_after:int,optimized:bool}
     */
    public function optimize(string $fullPath, ?string $expectedMime = null): array
    {
        if (!$this->isReadableFile($fullPath)) {
            throw new RuntimeException('file_not_readable');
        }

        $mime = $this->detectMime($fullPath);
        if ($expectedMime !== null && $mime !== '' && $mime !== $expectedMime) {
            throw new RuntimeException('mime_mismatch');
        }

        if (!\in_array($mime, $this->allowedMimes, true)) {
            throw new RuntimeException('mime_not_allowed');
        }

        $before = filesize($fullPath) ?: 0;
        if ($before <= 0) {
            throw new RuntimeException('empty_file');
        }
        if ($before > $this->maxFileSize) {
            throw new RuntimeException('file_too_large');
        }

        $this->optimizer->optimize($fullPath);
        clearstatcache(true, $fullPath);
        $after = filesize($fullPath) ?: $before;

        return [
            'bytes_before' => $before,
            'bytes_after'  => $after,
            'optimized'    => $after < $before,
        ];
    }

    private function isReadableFile(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path);
    }

    private function detectMime(string $fullPath): string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fullPath);
            return \is_string($mime) ? $mime : 'application/octet-stream';
        } catch (\Throwable) {
            return 'application/octet-stream';
        }
    }
}
