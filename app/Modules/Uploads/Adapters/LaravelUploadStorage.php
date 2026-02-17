<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Adapters;

use App\Application\Uploads\Contracts\UploadStorageInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class LaravelUploadStorage implements UploadStorageInterface
{
    public function deleteIfExists(string $disk, string $path): void
    {
        $resolvedDisk = trim($disk);
        $resolvedPath = trim($path);

        if ($resolvedDisk === '' || $resolvedPath === '') {
            return;
        }

        $filesystem = Storage::disk($resolvedDisk);
        if ($filesystem->exists($resolvedPath)) {
            $filesystem->delete($resolvedPath);
        }
    }

    public function writeStream(string $disk, string $path, mixed $stream): void
    {
        $resolvedDisk = trim($disk);
        $resolvedPath = trim($path);

        if ($resolvedDisk === '' || $resolvedPath === '') {
            throw new RuntimeException('Disk and path are required to persist upload.');
        }

        if (!is_resource($stream)) {
            throw new RuntimeException('Upload stream is invalid.');
        }

        $filesystem = Storage::disk($resolvedDisk);
        $written = $filesystem->put($resolvedPath, $stream);
        if ($written === false) {
            throw new RuntimeException('Unable to persist upload to storage.');
        }
    }

    public function size(string $disk, string $path): ?int
    {
        $resolvedDisk = trim($disk);
        $resolvedPath = trim($path);

        if ($resolvedDisk === '' || $resolvedPath === '') {
            return null;
        }

        try {
            $size = Storage::disk($resolvedDisk)->size($resolvedPath);
            return is_int($size) && $size > 0 ? $size : null;
        } catch (Throwable) {
            return null;
        }
    }
}
