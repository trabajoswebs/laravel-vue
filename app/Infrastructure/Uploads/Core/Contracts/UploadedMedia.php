<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Contracts;

/**
 * Wrapper agnóstico para archivos subidos.
 */
interface UploadedMedia
{
    public function originalName(): string;

    public function mimeType(): ?string;

    public function size(): ?int;

    public function raw(): mixed;
}
