<?php

declare(strict_types=1);

namespace App\Services\Optimizer\Adapters;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

/**
 * Descarga archivos remotos a un temporal local verificando el tamaño esperado.
 */
final class RemoteDownloader
{
    public function __construct(
        private readonly FilesystemAdapter $disk,
    ) {}

    public function download(string $relativePath, string $tempPath, int $expectedBytes): int
    {
        $stream = $this->disk->readStream($relativePath);
        if ($stream === false) {
            throw new RuntimeException('stream_read_failed');
        }

        $out = fopen($tempPath, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new RuntimeException('tmp_open_failed');
        }

        try {
            $copied = stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);
            fclose($stream);
        }

        if ($copied === false) {
            throw new RuntimeException('stream_copy_failed');
        }

        if ($copied < $expectedBytes) {
            throw new RuntimeException('stream_copy_incomplete');
        }

        return (int) $copied;
    }
}
