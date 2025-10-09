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
 * AvatarConversionProfile
 *
 * Este perfil centraliza la lógica de conversión de imágenes para la colección "avatar".
 * Define tamaños, formato (WebP), calidad y otras transformaciones de forma reutilizable
 * en modelos que implementen HasMedia.
 *
 * - Tamaños configurables
 * - Fuerza WEBP con optimize()
 * - Soporta cola (respeta config global o override local)
 * - Logging ante fallos (sin romper el flujo)
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
final class AvatarConversionProfile
{
    /**
     * Aplica las conversiones al modelo.
     *
     * Nota: $media no se usa hoy, pero se deja para futura lógica condicional
     * por mimetype/orientación si quisieras afinar por fichero.
     *
     * @param  HasMedia&InteractsWithMedia  $model  Modelo que implementa HasMedia e InteractsWithMedia.
     * @param  Media|null                   $media  Instancia de Media opcional (no utilizada actualmente).
     * @return void
     */
    public static function apply(HasMedia&InteractsWithMedia $model, ?Media $media = null): void
    {
        // 1) Tamaños desde config (con fallback)
        $sizes = config('image-pipeline.avatar_sizes', [
            'thumb'  => 128,
            'medium' => 256,
            'large'  => 512,
        ]);

        // Sanitiza/valida tamaños
        $sizes = collect($sizes)
            ->mapWithKeys(fn ($v, $k) => [$k => max(16, min(4096, (int) $v))])
            ->only(['thumb', 'medium', 'large'])
            ->all();

        // 2) Calidad WEBP (clamp defensivo)
        $webpQuality = (int) config('image-pipeline.webp_quality', 75);
        $webpQuality = max(1, min(100, $webpQuality));

        // 3) ¿Encolar conversions?
        $queueOverride = config('image-pipeline.avatar_queue_conversions'); // null => respetar Spatie
        $queued = is_null($queueOverride)
            ? (bool) config('media-library.queue_conversions_by_default', true)
            : (bool) $queueOverride;

        // Helper: perfil cuadrado → WEBP + optimize()
        $applySquareWebp = static function (Conversion $conversion, int $size) use ($webpQuality): void {
            $conversion
                ->fit(Fit::Crop, $size, $size)
                ->format('webp')
                ->quality($webpQuality)
                ->optimize()
                ->sharpen(10);
        };

        foreach ($sizes as $name => $px) {
            try {
                $conv = $model->addMediaConversion($name)
                    ->performOnCollections('avatar')
                    ->withResponsiveImages();

                $queued ? $conv->queued() : $conv->nonQueued();

                // Aplicar perfil
                $conv->tap(fn (Conversion $c) => $applySquareWebp($c, $px));
            } catch (\Throwable $e) {
                // No romper el flujo si una conversión falla; log y seguimos
                Log::error('avatar_conversion_profile_failed', [
                    'conversion' => $name,
                    'size'       => $px,
                    'error'      => str($e->getMessage())->limit(160)->toString(),
                ]);
            }
        }
    }
}