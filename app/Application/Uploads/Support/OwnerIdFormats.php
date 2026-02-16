<?php

declare(strict_types=1);

namespace App\Application\Uploads\Support;

final class OwnerIdFormats
{
    public const CANONICAL_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    public const CANONICAL_ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    private function __construct()
    {
    }

    public static function isCanonicalUuid(string $value): bool
    {
        return preg_match(self::CANONICAL_UUID_PATTERN, $value) === 1;
    }

    public static function isCanonicalUlid(string $value): bool
    {
        return preg_match(self::CANONICAL_ULID_PATTERN, $value) === 1;
    }
}
