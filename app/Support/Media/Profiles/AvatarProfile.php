<?php

declare(strict_types=1);

namespace App\Support\Media\Profiles;

use App\Support\Media\ImageProfile;
use App\Support\Media\ConversionProfiles\AvatarConversionProfile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
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
        return is_array($sizes) && !empty($sizes)
            ? array_keys($sizes)
            : self::DEFAULT_CONVERSIONS;
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
     * @param HasMedia&InteractsWithMedia $model Modelo que registra conversions.
     * @param Media|null $media Media actual (no necesario para registrar conversions).
     */
    public function applyConversions(HasMedia&InteractsWithMedia $model, ?Media $media = null): void
    {
        AvatarConversionProfile::apply($model, $media);
    }
}
