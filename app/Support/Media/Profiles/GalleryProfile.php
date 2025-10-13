<?php

declare(strict_types=1);

namespace App\Support\Media\Profiles;

use App\Support\Media\ImageProfile;
use App\Support\Media\ConversionProfiles\FileConstraints;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Perfil de imagen para una colección de galería/portfolio.
 *
 * - Define conversions típicas para visualización (thumb cuadrado, medium/large contenidas).
 * - Tamaños parametrizables vía config (gallery_sizes) con defaults seguros.
 */
final class GalleryProfile implements ImageProfile
{
    /** @inheritDoc */
    public function collection(): string
    {
        return (string) config('image-pipeline.gallery_collection', 'gallery');
    }

    /** @inheritDoc */
    public function disk(): ?string
    {
        $disk = config('image-pipeline.gallery_disk');
        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    /** @inheritDoc */
    public function conversions(): array
    {
        $sizes = config('image-pipeline.gallery_sizes', []);
        return is_array($sizes) && !empty($sizes)
            ? array_keys($sizes)
            : ['thumb', 'medium', 'large'];
    }

    /** @inheritDoc */
    public function fieldName(): string
    {
        return 'image';
    }

    /** @inheritDoc */
    public function requiresSquare(): bool
    {
        return false;
    }

    /**
     * Registra conversions en la colección de galería usando WebP + optimize.
     *
     * @param HasMedia&InteractsWithMedia $model
     * @param Media|null $media
     * @return void
     */
    public function applyConversions(HasMedia&InteractsWithMedia $model, ?Media $media = null): void
    {
        $sizes = config('image-pipeline.gallery_sizes', [
            'thumb'  => [320, 320, Fit::Crop],
            'medium' => [1280, 1280, Fit::Contain],
            'large'  => [2048, 2048, Fit::Contain],
        ]);
        $webpQ = FileConstraints::WEBP_QUALITY;
        $collection = $this->collection();

        $apply = static function (Conversion $c, int $w, int $h, Fit $fit) use ($webpQ): void {
            $c->fit($fit, $w, $h)->format('webp')->quality($webpQ)->optimize()->sharpen(10);
        };

        foreach ($sizes as $name => $def) {
            if (!is_string($name) || !is_array($def) || count($def) < 3) {
                continue;
            }
            [$w, $h, $fit] = $def;
            $conv = $model->addMediaConversion($name)
                ->performOnCollections($collection)
                ->withResponsiveImages();
            FileConstraints::QUEUE_CONVERSIONS_DEFAULT ? $conv->queued() : $conv->nonQueued();
            $conv->tap(fn(Conversion $c) => $apply($c, (int) $w, (int) $h, $fit));
        }
    }
}

