<?php

declare(strict_types=1);

namespace App\Support\Media\Profiles;

use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\ImageProfile;
use App\Support\Media\ConversionProfiles\AvatarConversionProfile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Perfil de imagen para la colección de avatar.
 *
 * - Usa las claves de config existentes: avatar_disk, avatar_collection, avatar_sizes.
 * - Delegan las conversions a AvatarConversionProfile (SSOT de avatar).
 */
final class AvatarProfile implements ImageProfile
{
    private const DEFAULT_COLLECTION  = 'avatar';
    private const DEFAULT_CONVERSIONS = ['thumb', 'medium', 'large'];

    /** @inheritDoc */
    public function collection(): string
    {
        return (string) config('image-pipeline.avatar_collection', self::DEFAULT_COLLECTION);
    }

    /** @inheritDoc */
    public function disk(): ?string
    {
        $disk = config('image-pipeline.avatar_disk');
        if (!is_string($disk)) {
            return null;
        }
        $disk = trim($disk);
        return $disk !== '' ? $disk : null;
    }

    /** @inheritDoc */
    public function conversions(): array
    {
        $sizes = config('image-pipeline.avatar_sizes', []);
        if (!is_array($sizes) || $sizes === []) {
            return self::DEFAULT_CONVERSIONS;
        }

        $names = [];
        foreach ($sizes as $name => $definition) {
            if (!is_string($name)) {
                continue;
            }

            $trimmed = trim($name);
            if ($trimmed === '') {
                continue;
            }

            $names[] = $trimmed;
        }

        return $names !== [] ? array_values(array_unique($names)) : self::DEFAULT_CONVERSIONS;
    }

    /** @inheritDoc */
    public function fieldName(): string
    {
        return 'avatar';
    }

    /** @inheritDoc */
    public function requiresSquare(): bool
    {
        // El perfil ya recorta 'thumb' a cuadrado; no forzar hard-constraint aquí.
        return false;
    }

    /**
     * Aplica conversions de avatar sobre el modelo.
     *
     * @param MediaOwner $model Modelo que registra conversions.
     * @param Media|null $media Media actual (no necesario para registrar conversions).
     */
    public function applyConversions(MediaOwner $model, ?Media $media = null): void
    {
        AvatarConversionProfile::apply($model, $media, $this->collection());
    }

    /** @inheritDoc */
    public function isSingleFile(): bool
    {
        return true;
    }
}
