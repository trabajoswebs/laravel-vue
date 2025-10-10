<?php

declare(strict_types=1);

namespace App\Support\Media\ConversionProfiles;

use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Define y aplica perfiles de conversión de imagen para avatares.
 *
 * Este perfil:
 * - Genera versiones en diferentes tamaños (thumb, medium, large).
 * - Usa calidad y dimensiones centralizadas en FileConstraints.
 * - Aplica formato WebP con optimización y ligero sharpen.
 * - Permite configurar si se ejecutan en cola o no.
 */
final class AvatarConversionProfile
{
    /**
     * Aplica las conversiones al modelo usando tamaños y calidad centralizados (FileConstraints).
     *
     * @param  HasMedia&InteractsWithMedia  $model
     * @param  Media|null                   $media
     * @return void
     */
    public static function apply(HasMedia&InteractsWithMedia $model, ?Media $media = null): void
    {
        // 1) Tamaños desde FileConstraints (SSOT)
        $sizes = [
            // nombre => [width, height, fit]
            'thumb'  => [FileConstraints::THUMB_WIDTH,  FileConstraints::THUMB_HEIGHT,  Fit::Crop],    // cuadrado
            'medium' => [FileConstraints::MEDIUM_WIDTH, FileConstraints::MEDIUM_HEIGHT, Fit::Contain], // respeta AR
            'large'  => [FileConstraints::LARGE_WIDTH,  FileConstraints::LARGE_HEIGHT,  Fit::Contain], // respeta AR
        ];

        // 2) Calidad WEBP desde FileConstraints
        $webpQuality = FileConstraints::WEBP_QUALITY;

        // 3) Modo de cola desde FileConstraints
        $queued = FileConstraints::QUEUE_CONVERSIONS_DEFAULT;

        // Helper para aplicar formato/quality común
        $applyWebp = static function (Conversion $conversion, int $w, int $h, Fit $fit) use ($webpQuality): void {
            $conversion
                ->fit($fit, $w, $h)
                ->format('webp')
                ->quality($webpQuality)
                ->optimize()
                ->sharpen(10);
        };

        foreach ($sizes as $name => [$w, $h, $fit]) {
            try {
                $conv = $model->addMediaConversion($name)
                    ->performOnCollections('avatar')   // si tienes User::AVATAR_COLLECTION, puedes usarlo aquí
                    ->withResponsiveImages();

                $queued ? $conv->queued() : $conv->nonQueued();

                $conv->tap(fn(Conversion $c) => $applyWebp($c, $w, $h, $fit));
            } catch (\Throwable $e) {
                Log::error('avatar_conversion_profile_failed', [
                    'conversion' => $name,
                    'width'      => $w,
                    'height'     => $h,
                    'fit'        => method_exists($fit, 'value') ? $fit->value : (string) $fit,
                    'error'      => str($e->getMessage())->limit(160)->toString(),
                ]);
            }
        }
    }
}
