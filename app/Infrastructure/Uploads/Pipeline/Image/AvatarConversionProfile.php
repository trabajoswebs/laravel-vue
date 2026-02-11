<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Image;

use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Support\Logging\SecurityLogger;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Perfil de conversión para imágenes de avatar.
 *
 * Genera versiones optimizadas (thumb, medium, large) en formato WebP,
 * respetando restricciones centralizadas de tamaño, calidad y procesamiento.
 *
 * Las conversiones:
 * - Usan dimensiones y calidad definidas en {@see FileConstraints}.
 * - Se aplican solo a la colección de avatares (configurable).
 * - Son encolables según la configuración de rendimiento.
 * - Incluyen optimización y ligero sharpen para mejorar percepción visual.
 */
final class AvatarConversionProfile
{
    /**
     * Aplica las conversiones de avatar al modelo dado.
     *
     * @param MediaOwner $model Modelo que usa {@see \Spatie\MediaLibrary\HasMedia}.
     * @param Media|null $media Instancia del medio (para logging y fallback).
     * @param AvatarProfile|null $profile Perfil que describe la colección/definiciones.
     */
    public static function apply(MediaOwner $model, ?Media $media = null, ?AvatarProfile $profile = null): void
    {
        $profile ??= app(AvatarProfile::class);

        if (!$model instanceof HasMedia) {
            SecurityLogger::warning('avatar_conversion_skipped_missing_trait', [
                'model'      => get_class($model),
                'media_id'   => $media?->id,
                'collection' => $profile->collection(),
            ]);

            return;
        }

        $constraints = app(FileConstraints::class);
        $collectionName = $profile->collection();
        $definitions = $profile->conversionDefinitions();
        $webpQuality = FileConstraints::WEBP_QUALITY;
        $queued = $constraints->queueConversionsForAvatar();

        foreach ($definitions as $name => $definition) {
            if (!self::definitionIsValid($definition)) {
                SecurityLogger::warning('avatar_conversion_invalid_definition', [
                    'conversion' => $name,
                    'definition' => $definition,
                ]);
                continue;
            }

            $width = (int) $definition['width'];
            $height = (int) $definition['height'];
            /** @var Fit $fit */
            $fit = $definition['fit'];

            try {
                $conversion = $model
                    ->addMediaConversion($name)
                    ->performOnCollections($collectionName)
                    ->withResponsiveImages();

                if ($queued) {
                    $conversion->queued();
                } else {
                    $conversion->nonQueued();
                }

                self::applyWebpFormatting($conversion, $width, $height, $fit, $webpQuality);
            } catch (\Throwable $e) {
                SecurityLogger::error('avatar_conversion_profile_failed', [
                    'conversion' => $name,
                    'width'      => $width,
                    'height'     => $height,
                    'fit'        => $fit->value ?? (string) $fit,
                    'error'      => str($e->getMessage())->limit(160)->toString(),
                ]);
            }
        }
    }

    /**
     * Valida que la definición contenga width/height/Fit válidos.
     *
     * @param array<string,mixed> $definition Definición de conversión
     * @return bool True si la definición es válida, false en caso contrario
     */
    private static function definitionIsValid(array $definition): bool
    {
        return isset($definition['width'], $definition['height'], $definition['fit'])
            && is_numeric($definition['width'])
            && is_numeric($definition['height'])
            && $definition['fit'] instanceof Fit;
    }

    /**
     * Aplica formato WebP a una conversión de imagen.
     *
     * @param Conversion $conversion Conversión a formatear
     * @param int $width Ancho de la imagen
     * @param int $height Alto de la imagen
     * @param Fit $fit Modo de ajuste de imagen
     * @param int $quality Calidad de la imagen WebP
     */
    private static function applyWebpFormatting(
        Conversion $conversion,
        int $width,
        int $height,
        Fit $fit,
        int $quality
    ): void {
        $conversion
            ->fit($fit, $width, $height)
            ->format('webp')
            ->quality($quality)
            ->optimize()
            ->sharpen(10);
    }
}
