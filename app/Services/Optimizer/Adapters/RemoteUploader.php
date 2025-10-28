<?php

declare(strict_types=1);

namespace App\Services\Optimizer\Adapters;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

/**
 * Sube archivos optimizados a un filesystem remoto usando streams.
 */
final class RemoteUploader
{
    public function __construct(
        private readonly FilesystemAdapter $disk,
    ) {}

    /**
     * @param array<string,mixed> $options
     */
    public function upload(string $relativePath, string $localPath, array $options = []): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('tmp_reopen_failed');
        }

        try {
            $result = $this->disk->put($relativePath, $handle, $options);
        } finally {
            fclose($handle);
        }

        if ($result === false) {
            throw new RuntimeException('remote_put_failed');
        }
    }
}
