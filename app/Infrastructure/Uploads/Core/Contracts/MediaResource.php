<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Contracts;

interface MediaResource
{
    public function getKey(): string|int;

    public function collectionName(): ?string;

    public function disk(): ?string;

    public function fileName(): ?string;

    public function url(): ?string;

    public function raw(): mixed;
}
