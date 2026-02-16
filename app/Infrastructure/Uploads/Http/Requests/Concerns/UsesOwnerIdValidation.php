<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests\Concerns;

use App\Application\Uploads\Support\OwnerIdFormats;
use App\Application\Uploads\Support\OwnerIdMode;

trait UsesOwnerIdValidation
{
    /**
     * @return array<int, mixed>
     */
    private function ownerIdRules(): array
    {
        $mode = OwnerIdMode::fromConfig((string) config('uploads.owner_id.mode', 'int'));

        return match ($mode) {
            OwnerIdMode::INT => ['nullable', 'integer', 'min:' . max(1, (int) config('uploads.owner_id.min_int', 1))],
            OwnerIdMode::UUID => ['nullable', 'string', $this->uuidOwnerIdRule()],
            OwnerIdMode::ULID => ['nullable', 'string', $this->ulidOwnerIdRule()],
        };
    }

    /**
     * @return \Closure(string, mixed, callable): void
     */
    private function uuidOwnerIdRule(): \Closure
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            if ($value === null) {
                return;
            }

            if (!is_string($value)) {
                $fail("El campo {$attribute} debe ser un UUID válido.");
                return;
            }

            $candidate = trim($value);
            if ($candidate === '') {
                return;
            }

            if (!OwnerIdFormats::isCanonicalUuid($candidate)) {
                $fail("El campo {$attribute} debe ser un UUID válido.");
            }
        };
    }

    /**
     * @return \Closure(string, mixed, callable): void
     */
    private function ulidOwnerIdRule(): \Closure
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            if ($value === null) {
                return;
            }

            if (!is_string($value)) {
                $fail("El campo {$attribute} debe ser un ULID válido.");
                return;
            }

            $candidate = trim($value);
            if ($candidate === '') {
                return;
            }

            if (!OwnerIdFormats::isCanonicalUlid($candidate)) {
                $fail("El campo {$attribute} debe ser un ULID válido.");
            }
        };
    }
}
