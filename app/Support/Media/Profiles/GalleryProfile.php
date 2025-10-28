<?php

declare(strict_types=1);

namespace App\Support\Media\Profiles;

use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\ImageProfile;
use App\Support\Media\ConversionProfiles\FileConstraints;
use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
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
        return array_keys($this->normalizedSizes());
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
     * @param MediaOwner $model
     * @param Media|null $media
     * @return void
     */
    public function applyConversions(MediaOwner $model, ?Media $media = null): void
    {
        /** @var FileConstraints $constraints */
        $constraints = app(FileConstraints::class);

        $sizes = $this->normalizedSizes();
        $webpQ = FileConstraints::WEBP_QUALITY;
        $collection = $this->collection();
        $queueDefault = $constraints->queueConversionsDefault();

        $apply = static function (Conversion $c, int $w, int $h, Fit $fit) use ($webpQ): void {
            $c->fit($fit, $w, $h)->format('webp')->quality($webpQ)->optimize()->sharpen(10);
        };

        foreach ($sizes as $name => [$w, $h, $fit]) {
            $conv = $model->addMediaConversion($name)
                ->performOnCollections($collection)
                ->withResponsiveImages();
            $queueDefault ? $conv->queued() : $conv->nonQueued();
            $conv->tap(fn(Conversion $c) => $apply($c, (int) $w, (int) $h, $fit));
        }
    }

    /** @inheritDoc */
    public function isSingleFile(): bool
    {
        return false;
    }

    /**
     * @return array<string,array{0:int,1:int,2:Fit}>
     */
    private function normalizedSizes(): array
    {
        $defaults = [
            'thumb'  => [320, 320, Fit::Crop],
            'medium' => [1280, 1280, Fit::Contain],
            'large'  => [2048, 2048, Fit::Contain],
        ];

        $raw = config('image-pipeline.gallery_sizes');
        if (!is_array($raw) || $raw === []) {
            return $defaults;
        }

        $normalized = [];
        foreach ($raw as $name => $definition) {
            if (!is_string($name) || trim($name) === '') {
                Log::warning('media.gallery_sizes.invalid_name', [
                    'name' => is_scalar($name) ? (string) $name : gettype($name),
                ]);
                continue;
            }

            $normalizedDef = $this->normalizeDefinition(trim($name), $definition, $defaults[$name] ?? null);
            if ($normalizedDef !== null) {
                $normalized[$name] = $normalizedDef;
                continue;
            }

            if (isset($defaults[$name])) {
                $normalized[$name] = $defaults[$name];
            }
        }

        return $normalized !== [] ? $normalized : $defaults;
    }

    /**
     * @param mixed $definition
     * @param array{0:int,1:int,2:Fit}|null $fallback
     * @return array{0:int,1:int,2:Fit}|null
     */
    private function normalizeDefinition(string $name, mixed $definition, ?array $fallback): ?array
    {
        $width = null;
        $height = null;
        $fitValue = null;

        if (is_array($definition)) {
            if (array_is_list($definition)) {
                $width = $definition[0] ?? null;
                $height = $definition[1] ?? null;
                $fitValue = $definition[2] ?? null;
            } else {
                $width = $definition['width'] ?? ($definition['w'] ?? null);
                $height = $definition['height'] ?? ($definition['h'] ?? null);
                $fitValue = $definition['fit'] ?? null;
            }
        } else {
            Log::warning('media.gallery_sizes.invalid_definition', [
                'name' => $name,
                'type' => gettype($definition),
            ]);
            return $fallback;
        }

        $width = $this->toPositiveInt($width);
        $height = $this->toPositiveInt($height);
        $fit = $this->coerceFit($fitValue);

        if ($width === null || $height === null || $fit === null) {
            Log::warning('media.gallery_sizes.invalid_entry', [
                'name'   => $name,
                'width'  => $width,
                'height' => $height,
                'fit'    => $fitValue,
            ]);
            return $fallback;
        }

        return [$width, $height, $fit];
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        return null;
    }

    private function coerceFit(mixed $value): ?Fit
    {
        if ($value instanceof Fit) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $normalized = str_replace([' ', '-'], '_', $normalized);

            foreach (Fit::cases() as $case) {
                if (
                    $normalized === strtolower($case->value) ||
                    $normalized === strtolower($case->name)
                ) {
                    return $case;
                }
            }
        }

        return null;
    }
}
