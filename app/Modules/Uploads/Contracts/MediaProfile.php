<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Contracts;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Describe cómo debe manejarse una colección de media para un owner.
 */
interface MediaProfile
{
    public function collection(): string;

    public function disk(): ?string;

    /**
     * @return array<int,string>
     */
    public function conversions(): array;

    public function fieldName(): string;

    public function requiresSquare(): bool;

    public function applyConversions(MediaOwner $model, ?Media $media = null): void;

    public function isSingleFile(): bool;

    public function fileConstraints(): FileConstraints;

    public function usesQuarantine(): bool;

    public function usesAntivirus(): bool;

    public function requiresImageNormalization(): bool;

    public function getQuarantineTtlHours(): int;

    public function getFailedTtlHours(): int;
}
