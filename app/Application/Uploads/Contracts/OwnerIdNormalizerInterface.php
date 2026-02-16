<?php

declare(strict_types=1);

namespace App\Application\Uploads\Contracts;

interface OwnerIdNormalizerInterface
{
    public function normalize(mixed $ownerId): int|string|null;
}
