<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Services;

use App\Application\Uploads\Contracts\OwnerIdNormalizerInterface;
use App\Application\Uploads\Exceptions\InvalidOwnerIdException;
use App\Application\Uploads\Support\OwnerIdFormats;
use App\Application\Uploads\Support\OwnerIdMode;

final class ConfigurableOwnerIdNormalizer implements OwnerIdNormalizerInterface
{
    public function normalize(mixed $ownerId): int|string|null
    {
        if ($ownerId === null) {
            return null;
        }

        $mode = OwnerIdMode::fromConfig((string) config('uploads.owner_id.mode', 'int'));

        return match ($mode) {
            OwnerIdMode::INT => $this->normalizeIntegerOwnerId($ownerId),
            OwnerIdMode::UUID => $this->normalizeUuidOwnerId($ownerId),
            OwnerIdMode::ULID => $this->normalizeUlidOwnerId($ownerId),
        };
    }

    private function normalizeIntegerOwnerId(mixed $ownerId): ?int
    {
        $min = max(1, (int) config('uploads.owner_id.min_int', 1));

        if (!is_int($ownerId) && !is_string($ownerId)) {
            throw new InvalidOwnerIdException(sprintf('ownerId inválido para modo int (min=%d).', $min));
        }

        $raw = is_string($ownerId) ? trim($ownerId) : $ownerId;

        if ($raw === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min],
        ]);

        if ($value === false) {
            throw new InvalidOwnerIdException(sprintf('ownerId inválido para modo int (min=%d).', $min));
        }

        return $value;
    }

    private function normalizeUuidOwnerId(mixed $ownerId): ?string
    {
        if (!is_string($ownerId)) {
            throw new InvalidOwnerIdException('ownerId inválido para modo uuid.');
        }

        $value = trim($ownerId);
        if ($value === '') {
            return null;
        }

        if (!OwnerIdFormats::isCanonicalUuid($value)) {
            throw new InvalidOwnerIdException('ownerId inválido para modo uuid.');
        }

        return strtolower($value);
    }

    private function normalizeUlidOwnerId(mixed $ownerId): ?string
    {
        if (!is_string($ownerId)) {
            throw new InvalidOwnerIdException('ownerId inválido para modo ulid.');
        }

        $value = trim($ownerId);
        if ($value === '') {
            return null;
        }

        if (!OwnerIdFormats::isCanonicalUlid($value)) {
            throw new InvalidOwnerIdException('ownerId inválido para modo ulid.');
        }

        return strtoupper($value);
    }
}
