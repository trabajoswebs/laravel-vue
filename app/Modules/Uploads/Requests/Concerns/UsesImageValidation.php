<?php // Trait para centralizar reglas de validación de imágenes

declare(strict_types=1); // Tipado estricto

namespace App\Modules\Uploads\Requests\Concerns; // Namespace del trait

use App\Modules\Uploads\Contracts\FileConstraints as FC; // Contrato de constraints
use App\Modules\Uploads\Rules\SecureImageValidation; // Regla de validación segura
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
        $allowedExt = $constraints->allowedExtensions(); // Extensiones permitidas
        $disallowedExt = array_map('strtolower', (array) config('image-pipeline.disallowed_extensions', [])); // Ext prohibidas
        $disallowedMimes = array_map('strtolower', (array) config('image-pipeline.disallowed_mimes', [])); // MIMEs prohibidos

        return [
            'bail', // Corta en el primer fallo
            'required', // Campo obligatorio
            'file', // Debe ser archivo
            File::types($allowedExt)->max($maxKb), // Limita extensión/tamaño (evita fallo en drivers sin soporte AVIF)
            // La validación profunda de MIME/dimensiones se mantiene en SecureImageValidation
            // para evitar divergencias y falsos positivos por doble validación.
            new SecureImageValidation( // Validación profunda de imagen
                maxFileSizeBytes: $maxBytes, // Límite de bytes
                normalize: true, // Normaliza la imagen
                constraints: $constraints // Constraints compartidas
            ),
            static function (string $attribute, mixed $value, Closure $fail) use ( // Validación inline adicional
                $disallowedExt,
                $disallowedMimes
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

            },
        ];
    }
}
