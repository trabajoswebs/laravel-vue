<?php

declare(strict_types=1);

namespace App\Domain\User\Profiles;

use App\Domain\User\Contracts\MediaOwner;
use App\Domain\User\ConversionProfiles\AvatarConversionProfile;
use App\Domain\User\ConversionProfiles\FileConstraints;
use App\Domain\Media\ImageProfile;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Perfil de imagen específico para la colección de avatar.
 *
 * Define la configuración predeterminada para la colección 'avatar',
 * como el disco, los tamaños de conversión y el nombre del campo.
 * Obtiene la configuración desde `config('image-pipeline')`.
 * Delega la lógica de aplicación de conversiones a `AvatarConversionProfile`.
 */
final class AvatarProfile implements ImageProfile
{
    /**
     * Nombre de la colección predeterminada para avatares.
     */
    private const DEFAULT_COLLECTION  = 'avatar';

    /**
     * Definiciones predeterminadas para cada conversión.
     *
     * @var array<string,array{width:int,height:int,fit:Fit}>
     */
    private const DEFAULT_DEFINITIONS = [
        'thumb' => [
            'width' => FileConstraints::THUMB_WIDTH,
            'height' => FileConstraints::THUMB_HEIGHT,
            'fit' => Fit::Crop,
        ],
        'medium' => [
            'width' => FileConstraints::MEDIUM_WIDTH,
            'height' => FileConstraints::MEDIUM_HEIGHT,
            'fit' => Fit::Contain,
        ],
        'large' => [
            'width' => FileConstraints::LARGE_WIDTH,
            'height' => FileConstraints::LARGE_HEIGHT,
            'fit' => Fit::Contain,
        ],
    ];

    /**
     * Cache local de definiciones de conversión.
     *
     * @var array<string,array{width:int,height:int,fit:Fit}>|null
     */
    private ?array $conversionDefinitionsCache = null;

    /**
     * {@inheritDoc}
     *
     * Devuelve el nombre de la colección para avatares.
     * Se obtiene desde la configuración, o se usa un valor predeterminado.
     */
    public function collection(): string
    {
        return (string) config('image-pipeline.avatar_collection', self::DEFAULT_COLLECTION);
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve el nombre del disco de almacenamiento para avatares.
     * Se obtiene desde la configuración. Devuelve null si no está definido o está vacío.
     */
    public function disk(): ?string
    {
        $disk = config('image-pipeline.avatar_disk');
        if (!is_string($disk)) {
            return null;
        }
        $disk = trim($disk);
        return $disk !== '' ? $disk : null;
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve una lista de nombres de conversiones disponibles para avatares.
     * Se obtiene desde la configuración. Si no hay configuración, se usan las predeterminadas.
     */
    public function conversions(): array
    {
        return array_keys($this->conversionDefinitions());
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve el nombre del campo asociado al avatar.
     */
    public function fieldName(): string
    {
        return 'avatar';
    }

    /**
     * {@inheritDoc}
     *
     * Indica si los archivos de esta colección deben ser estrictamente cuadrados.
     * En este caso, se devuelve `false` porque la lógica de recorte cuadrado se aplica
     * en las conversiones (por ejemplo, 'thumb'), no a nivel general del perfil.
     */
    public function requiresSquare(): bool
    {
        // El perfil ya recorta 'thumb' a cuadrado; no forzar hard-constraint aquí.
        return false;
    }

    /**
     * Aplica las conversiones específicas de avatar sobre el modelo.
     *
     * Este método delega la lógica de registro de conversiones
     * a la clase `AvatarConversionProfile`.
     *
     * @param MediaOwner $model Modelo que posee el medio y al que se le aplicarán las conversiones.
     * @param Media|null $media (Opcional) El modelo de medio actual. Puede no ser necesario para registrar las conversiones.
     *
     * @return void
     */
    public function applyConversions(MediaOwner $model, ?Media $media = null): void
    {
        AvatarConversionProfile::apply($model, $media, $this);
    }

    /**
     * {@inheritDoc}
     *
     * Indica si esta colección almacena un solo archivo.
     * Para avatares, normalmente es `true`.
     */
    public function isSingleFile(): bool
    {
        return true;
    }

    /**
     * Mapa de conversiones con dimensiones y estrategia de ajuste.
     *
     * @return array<string,array{width:int,height:int,fit:Fit}>
     */
    public function conversionDefinitions(): array
    {
        if ($this->conversionDefinitionsCache !== null) {
            return $this->conversionDefinitionsCache;
        }

        $configured = config('image-pipeline.avatar_sizes');
        $definitions = [];

        if (is_array($configured) && $configured !== []) {
            foreach ($configured as $name => $value) {
                if (!is_string($name)) {
                    continue;
                }
                $normalized = trim($name);
                if ($normalized === '') {
                    continue;
                }

                $definitions[$normalized] = $this->buildDefinition($normalized, $value);
            }
        }

        if ($definitions === []) {
            $definitions = self::DEFAULT_DEFINITIONS;
        }

        return $this->conversionDefinitionsCache = $definitions;
    }

    /**
     * Construye una definición de conversión a partir de la configuración.
     *
     * @param string $name Nombre de la conversión.
     * @param mixed $value Valor configurado (número o array).
     * @return array{width:int,height:int,fit:Fit}
     */
    private function buildDefinition(string $name, mixed $value): array
    {
        $defaults = self::DEFAULT_DEFINITIONS[$name] ?? self::DEFAULT_DEFINITIONS['medium'];

        $width = (int) $defaults['width'];
        $height = (int) $defaults['height'];
        $fit = $defaults['fit'];

        if (is_numeric($value)) {
            $width = $height = max(1, (int) $value);
        } elseif (is_array($value)) {
            if (isset($value['width']) && is_numeric($value['width'])) {
                $width = max(1, (int) $value['width']);
            }

            if (isset($value['height']) && is_numeric($value['height'])) {
                $height = max(1, (int) $value['height']);
            }

            if (isset($value['fit']) && is_string($value['fit'])) {
                $resolvedFit = $this->resolveFitEnum($value['fit']);
                if ($resolvedFit !== null) {
                    $fit = $resolvedFit;
                }
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'fit' => $fit,
        ];
    }

    private function resolveFitEnum(string $candidate): ?Fit
    {
        $normalized = strtolower(trim($candidate));

        return Fit::tryFrom($normalized);
    }
}
