<?php

declare(strict_types=1);

namespace App\Application\Uploads\Support;

enum OwnerIdMode: string
{
    case INT = 'int';
    case UUID = 'uuid';
    case ULID = 'ulid';

    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::INT;
    }
}
