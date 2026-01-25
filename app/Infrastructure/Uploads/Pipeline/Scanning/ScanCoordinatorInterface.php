<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning;

use Illuminate\Http\UploadedFile;

interface ScanCoordinatorInterface
{
    public function enabled(): bool;

    /**
     * @param array<string,mixed> $context
     */
    public function scan(UploadedFile $file, string $path, array $context = []): void;
}
