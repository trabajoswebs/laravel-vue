<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Profiles;

use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;
use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
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
final class GalleryProfile implements MediaProfile
{
    /**
     * {@inheritDoc}
     *
     * Devuelve el nombre de la colección para galería.
     * Se obtiene desde la configuración o se usa 'gallery' por defecto.
     */
    public function collection(): string
    {
        return (string) config('image-pipeline.gallery_collection', 'gallery');
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve el nombre del disco de almacenamiento para galería.
     * Se obtiene desde la configuración. Devuelve null si no está definido o está vacío.
     */
    public function disk(): ?string
    {
        $disk = config('image-pipeline.gallery_disk');
        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve una lista de nombres de conversiones disponibles para galería.
     * Se basa en los tamaños normalizados configurados.
     */
    public function conversions(): array
    {
        return array_keys($this->normalizedSizes());
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve el nombre del campo asociado a la galería.
     * 
     * @return string Nombre del campo (en este caso 'image')
     */
    public function fieldName(): string
    {
        return 'image';
    }

    /**
     * {@inheritDoc}
     *
     * Indica si los archivos de esta colección deben ser estrictamente cuadrados.
     * Para galería, se devuelve `false` ya que se permiten diferentes proporciones.
     */
    public function requiresSquare(): bool
    {
        return false;
    }

    /**
     * Registra conversions en la colección de galería usando WebP + optimize.
     *
     * Aplica las conversiones configuradas a las imágenes de la galería,
     * convirtiéndolas a formato WebP con calidad óptima y aplicando optimizaciones.
     *
     * @param MediaOwner $model Modelo que posee el medio y al que se le aplicarán las conversiones.
     * @param Media|null $media (Opcional) El modelo de medio actual.
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

        // Función anónima para aplicar configuraciones comunes a las conversiones
        $apply = static function (Conversion $c, int $w, int $h, Fit $fit) use ($webpQ): void {
            $c->fit($fit, $w, $h)->format('webp')->quality($webpQ)->optimize()->sharpen(10);
        };

        // Iterar sobre los tamaños y crear las conversiones correspondientes
        foreach ($sizes as $name => [$w, $h, $fit]) {
            $conv = $model->addMediaConversion($name)
                ->performOnCollections($collection)
                ->withResponsiveImages();
            $queueDefault ? $conv->queued() : $conv->nonQueued();
            $conv->tap(fn(Conversion $c) => $apply($c, (int) $w, (int) $h, $fit));
        }
    }

    /**
     * {@inheritDoc}
     *
     * Indica si esta colección almacena un solo archivo.
     * Para galería, es `false` porque puede contener múltiples imágenes.
     */
    public function isSingleFile(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve las restricciones de archivo para este perfil.
     */
    public function fileConstraints(): FileConstraints
    {
        return app(FileConstraints::class);
    }

    /**
     * {@inheritDoc}
     *
     * Indica si este perfil utiliza cuarentena para los archivos subidos.
     */
    public function usesQuarantine(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Indica si este perfil utiliza antivirus para los archivos subidos.
     */
    public function usesAntivirus(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Indica si este perfil requiere normalización de imágenes.
     */
    public function requiresImageNormalization(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve el tiempo de vida útil (TTL) en horas para archivos en cuarentena.
     */
    public function getQuarantineTtlHours(): int
    {
        return (int) config(
            'image-pipeline.gallery_quarantine_ttl_hours',
            (int) config('image-pipeline.quarantine_pending_ttl_hours', 24)
        );
    }

    /**
     * {@inheritDoc}
     *
     * Devuelve el tiempo de vida útil (TTL) en horas para archivos que fallaron.
     */
    public function getFailedTtlHours(): int
    {
        return (int) config(
            'image-pipeline.gallery_failed_ttl_hours',
            (int) config('image-pipeline.quarantine_failed_ttl_hours', 4)
        );
    }

    /**
     * Normaliza y devuelve los tamaños de conversión configurados.
     *
     * Combina la configuración personalizada con los valores predeterminados
     * y maneja errores de configuración.
     *
     * @return array<string,array{0:int,1:int,2:Fit}> Array de tamaños normalizados
     */
    private function normalizedSizes(): array
    {
        // Tamaños predeterminados para galería
        $defaults = [
            'thumb'  => [320, 320, Fit::Crop],      // Miniatura cuadrada
            'medium' => [1280, 1280, Fit::Contain], // Tamaño medio contenido
            'large'  => [2048, 2048, Fit::Contain], // Tamaño grande contenido
        ];

        $raw = config('image-pipeline.gallery_sizes');
        if (!is_array($raw) || $raw === []) {
            return $defaults;
        }

        $normalized = [];
        foreach ($raw as $name => $definition) {
            // Validar que el nombre sea una cadena válida
            if (!is_string($name) || trim($name) === '') {
                Log::warning('media.gallery_sizes.invalid_name', [
                    'name' => is_scalar($name) ? (string) $name : gettype($name),
                ]);
                continue;
            }

            // Normalizar la definición de tamaño
            $normalizedDef = $this->normalizeDefinition(trim($name), $definition, $defaults[$name] ?? null);
            if ($normalizedDef !== null) {
                $normalized[$name] = $normalizedDef;
                continue;
            }

            // Si la normalización falla, usar el valor predeterminado si existe
            if (isset($defaults[$name])) {
                $normalized[$name] = $defaults[$name];
            }
        }

        // Si no hay tamaños válidos, usar los predeterminados
        return $normalized !== [] ? $normalized : $defaults;
    }

    /**
     * Normaliza una definición individual de tamaño.
     *
     * Convierte diferentes formatos de configuración en un array estándar
     * [ancho, alto, ajuste] o devuelve null si no es válida.
     *
     * @param string $name Nombre de la conversión
     * @param mixed $definition Definición de tamaño (array o valor inválido)
     * @param array{0:int,1:int,2:Fit}|null $fallback Valor de respaldo si la normalización falla
     * @return array{0:int,1:int,2:Fit}|null Definición normalizada o null si inválida
     */
    private function normalizeDefinition(string $name, mixed $definition, ?array $fallback): ?array
    {
        $width = null;
        $height = null;
        $fitValue = null;

        // Procesar la definición según su formato
        if (is_array($definition)) {
            if (array_is_list($definition)) {
                // Formato: [ancho, alto, ajuste]
                $width = $definition[0] ?? null;
                $height = $definition[1] ?? null;
                $fitValue = $definition[2] ?? null;
            } else {
                // Formato: ['width' => ..., 'height' => ..., 'fit' => ...] o ['w' => ..., 'h' => ...]
                $width = $definition['width'] ?? ($definition['w'] ?? null);
                $height = $definition['height'] ?? ($definition['h'] ?? null);
                $fitValue = $definition['fit'] ?? null;
            }
        } else {
            // Si no es un array, registrar advertencia y usar fallback
            Log::warning('media.gallery_sizes.invalid_definition', [
                'name' => $name,
                'type' => gettype($definition),
            ]);
            return $fallback;
        }

        // Convertir valores a enteros positivos y ajuste válido
        $width = $this->toPositiveInt($width);
        $height = $this->toPositiveInt($height);
        $fit = $this->coerceFit($fitValue);

        // Si algún valor es inválido, registrar advertencia y usar fallback
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

    /**
     * Convierte un valor a un entero positivo.
     *
     * @param mixed $value Valor a convertir
     * @return int|null Entero positivo o null si inválido
     */
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

    /**
     * Convierte un valor a una constante Fit válida.
     *
     * Acepta cadenas o instancias de Fit y las convierte al enum Fit correspondiente.
     *
     * @param mixed $value Valor a convertir
     * @return Fit|null Constante Fit válida o null si inválida
     */
    private function coerceFit(mixed $value): ?Fit
    {
        if ($value instanceof Fit) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $normalized = str_replace([' ', '-'], '_', $normalized);

            // Buscar coincidencia con los casos del enum Fit
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
