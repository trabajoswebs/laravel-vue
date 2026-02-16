<?php

declare(strict_types=1);

namespace App\Application\Uploads\Contracts;

/**
 * Puerto para operaciones de almacenamiento de artefactos de upload.
 */
interface UploadStorageInterface
{
    public function deleteIfExists(string $disk, string $path): void;

    /**
     * @param resource $stream
     */
    public function writeStream(string $disk, string $path, mixed $stream): void;

    public function size(string $disk, string $path): ?int;
}
