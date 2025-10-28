<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para perfiles de conversión de medios.
namespace App\Support\Media\ConversionProfiles;

// 3. Importaciones de clases y enums necesarios.
use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
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
     * @param object $model El modelo que posee el medio (debe usar el trait HasMedia).
     * @param Media|null $media Instancia del modelo Media (opcional).
     * @param string|null $collection Nombre de la colección de medios (opcional).
     * @return void
     */
    public static function apply(object $model, ?Media $media = null, ?string $collection = null): void
    {
        // Verifica si el modelo tiene el método necesario para agregar conversiones (HasMedia trait).
        if (!method_exists($model, 'addMediaConversion')) {
            // Registra un error si el modelo no tiene el trait necesario.
            Log::warning('avatar_conversion_skipped_missing_trait', [
                'model'      => get_class($model),
                'media_id'   => $media?->id,                   // ✅ usa $media
                'collection' => $collection ?? $media?->collection_name
                    ?? config('image-pipeline.avatar_collection', 'avatar'),
            ]);
            return; // Sale del método si no se puede aplicar el perfil.
        }

        // 4. Obtiene la instancia de FileConstraints para obtener configuraciones centralizadas.
        /** @var FileConstraints $constraints */
        $constraints = app(FileConstraints::class);

        // 5. Normaliza el nombre de la colección para aplicar las conversiones.
        $collectionName = self::normalizeCollection($collection);

        // 6. Define los tamaños de las conversiones basados en FileConstraints (SSOT - Single Source of Truth).
        $sizes = [
            // nombre => [width, height, fit]
            'thumb'  => [FileConstraints::THUMB_WIDTH,  FileConstraints::THUMB_HEIGHT,  Fit::Crop],    // cuadrado
            'medium' => [FileConstraints::MEDIUM_WIDTH, FileConstraints::MEDIUM_HEIGHT, Fit::Contain], // respeta AR
            'large'  => [FileConstraints::LARGE_WIDTH,  FileConstraints::LARGE_HEIGHT,  Fit::Contain], // respeta AR
        ];

        // 7. Obtiene la calidad para el formato WebP desde FileConstraints.
        $webpQuality = FileConstraints::WEBP_QUALITY;

        // 8. Determina si las conversiones deben ejecutarse en cola o no.
        $queued = $constraints->queueConversionsForAvatar();

        // 9. Función auxiliar para aplicar el formato WebP, calidad, optimización y ligero sharpen.
        $applyWebp = static function (Conversion $conversion, int $w, int $h, Fit $fit) use ($webpQuality): void {
            $conversion
                ->fit($fit, $w, $h)  // Ajusta la imagen al tamaño y tipo especificado.
                ->format('webp')     // Establece el formato de salida a WebP.
                ->quality($webpQuality) // Aplica la calidad WebP.
                ->optimize()         // Optimiza la imagen para tamaño.
                ->sharpen(10);       // Aplica un ligero enfoque.
        };

        // 10. Itera sobre cada tamaño definido y aplica la conversión.
        foreach ($sizes as $name => [$w, $h, $fit]) {
            try {
                // Crea la conversión con el nombre correspondiente.
                $conversion = $model->addMediaConversion($name)
                    ->performOnCollections($collectionName) // Aplica solo a la colección especificada.
                    ->withResponsiveImages();               // Genera imágenes adaptables.

                // Configura si la conversión se ejecuta en cola o no.
                $queued ? $conversion->queued() : $conversion->nonQueued();

                // Aplica el formato y ajustes WebP definidos anteriormente.
                $applyWebp($conversion, $w, $h, $fit);
            } catch (\Throwable $e) {
                // Registra un error si la creación de la conversión falla.
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

    /**
     * Normaliza el nombre de la colección, usando un valor por defecto si es necesario.
     *
     * @param string|null $collection Nombre de la colección a normalizar.
     * @return string Nombre de la colección normalizado o por defecto.
     */
    private static function normalizeCollection(?string $collection): string
    {
        // 11. Obtiene el nombre de la colección y lo limpia.
        $configured = is_string($collection) ? trim($collection) : '';
        if ($configured !== '') {
            return $configured; // Si es válido, lo devuelve.
        }

        // 12. Busca un valor por defecto en la configuración.
        $fallback = config('image-pipeline.avatar_collection', 'avatar');

        // 13. Devuelve el valor por defecto de la configuración o 'avatar' si no es válido.
        return is_string($fallback) && $fallback !== '' ? $fallback : 'avatar';
    }
}
