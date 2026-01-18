<?php // Trait para centralizar reglas de validación de imágenes

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Http\Requests\Concerns; // Namespace del trait

use App\Application\Uploads\Media\Contracts\FileConstraints as FC; // Contrato de constraints
use App\Infrastructure\Uploads\Http\Rules\SecureImageValidation; // Regla de validación segura
use Closure; // Tipo Closure para validaciones inline
use Illuminate\Http\UploadedFile; // Archivo subido
use Illuminate\Validation\Rules\File; // Regla File

/**
 * Expone helper para construir reglas de validación de imagen.
 */
trait UsesImageValidation // Trait reutilizable para FormRequests
{
    /**
     * Construye las reglas para un campo de imagen.
     *
     * @param string $field Nombre del campo
     * @return array<int,mixed> Reglas aplicables
     */
    protected function imageRules(string $field): array // Devuelve reglas compartidas
    {
        /** @var FC $constraints */
        $constraints = app(FC::class); // Obtiene constraints globales

        $maxBytes = min($constraints->maxBytes, FC::MAX_BYTES); // Límite de bytes permitido
        $maxKb = (int) ceil($maxBytes / 1024); // Límite en KB para File::max
        $minDim = max($constraints->minDimension, FC::MIN_WIDTH); // Dimensión mínima
        $maxDim = min($constraints->maxDimension, FC::MAX_WIDTH); // Dimensión máxima
        $allowedExt = $constraints->allowedExtensions(); // Extensiones permitidas
        $allowedMimes = $constraints->allowedMimeTypes(); // MIMEs permitidos
        $disallowedExt = array_map('strtolower', (array) config('image-pipeline.disallowed_extensions', [])); // Ext prohibidas
        $disallowedMimes = array_map('strtolower', (array) config('image-pipeline.disallowed_mimes', [])); // MIMEs prohibidos
        $maxMegapixels = $constraints->maxMegapixels; // Límite de megapíxeles

        return [
            'bail', // Corta en el primer fallo
            'required', // Campo obligatorio
            'file', // Debe ser archivo
            File::image()->max($maxKb)->types($allowedExt), // Regla File con tipos y tamaño
            'mimetypes:' . implode(',', $allowedMimes), // MIME real permitido
            "dimensions:min_width={$minDim},min_height={$minDim},max_width={$maxDim},max_height={$maxDim}", // Guard de dimensiones
            new SecureImageValidation( // Validación profunda de imagen
                maxFileSizeBytes: $maxBytes, // Límite de bytes
                normalize: true, // Normaliza la imagen
                constraints: $constraints // Constraints compartidas
            ),
            static function (string $attribute, mixed $value, Closure $fail) use ( // Validación inline adicional
                $disallowedExt,
                $disallowedMimes,
                $maxMegapixels
            ): void {
                if (!$value instanceof UploadedFile) { // Si no es UploadedFile
                    return; // No aplica validación
                }

                $mime = strtolower((string) $value->getMimeType()); // MIME inferido
                if ($mime !== '' && in_array($mime, $disallowedMimes, true)) { // MIME prohibido
                    $fail(__('image-pipeline.validation.avatar_mime')); // Rechaza
                    return;
                }

                $ext = strtolower((string) $value->getClientOriginalExtension()); // Extensión original
                if ($ext !== '' && in_array($ext, $disallowedExt, true)) { // Ext prohibida
                    $fail(__('image-pipeline.validation.avatar_mime')); // Rechaza
                    return;
                }

                $path = $value->getRealPath(); // Ruta temporal
                if ($path !== false) { // Si hay ruta
                    $info = @getimagesize($path); // Lee dimensiones
                    if (is_array($info) && isset($info[0], $info[1])) { // Si hay ancho/alto
                        $megapixels = ($info[0] * $info[1]) / 1_000_000; // Calcula megapíxeles
                        if ($megapixels > $maxMegapixels) { // Excede límite
                            $fail(__('file-constraints.megapixels_exceeded', ['max' => $maxMegapixels])); // Rechaza
                        }
                    }
                }
            },
        ];
    }
}
