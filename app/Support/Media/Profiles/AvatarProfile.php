<?php

declare(strict_types=1);

namespace App\Support\Media\Profiles;

use App\Support\Media\Contracts\MediaOwner;
use App\Support\Media\ImageProfile;
use App\Support\Media\ConversionProfiles\AvatarConversionProfile;
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
     * Nombres de conversiones predeterminadas para avatares.
     */
    private const DEFAULT_CONVERSIONS = ['thumb', 'medium', 'large'];

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

        // Filtra duplicados y asegura que no esté vacío.
        return $names !== [] ? array_values(array_unique($names)) : self::DEFAULT_CONVERSIONS;
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
        AvatarConversionProfile::apply($model, $media, $this->collection());
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
}