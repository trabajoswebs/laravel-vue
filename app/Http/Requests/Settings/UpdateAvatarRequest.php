<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use App\Rules\SecureImageValidation;
use App\Support\Media\ConversionProfiles\FileConstraints as FC;

/**
 * Solicitud para validar la actualización del avatar del usuario.
 * 
 * Incluye validación de tipo, tamaño, dimensiones, MIME y seguridad de la imagen.
 * La autorización fina se maneja en el Policy o Controller.
 */
class UpdateAvatarRequest extends FormRequest
{
    /**
     * Autorización basada en la Policy `updateAvatar`.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('updateAvatar', $user);
    }

    /**
     * Reglas de validación para la subida de avatar.
     *
     * Notas:
     * - Se usa `bail` para cortar en el primer fallo (reduce coste y ruido).
     * - `File::image()` asegura que sea imagen válida a nivel de MIME.
     * - Extensiones/MIME y límites (KB, dimensiones) provienen de FileConstraints.
     * - `dimensions` es un guard rápido; la validación profunda la hace SecureImageValidation.
     */
    public function rules(): array
    {
        /** @var FC $constraints */
        $constraints = app(FC::class);

        // Laravel espera kilobytes en File::max().
        $maxBytes = min($constraints->maxBytes, FC::MAX_BYTES);
        $maxKb    = (int) ceil($maxBytes / 1024);

        // Dimensiones mín./máx. centralizadas (mismo valor para ancho/alto).
        $minDim = max($constraints->minDimension, FC::MIN_WIDTH);
        $maxDim = min($constraints->maxDimension, FC::MAX_WIDTH);

        $allowedExt   = $constraints->allowedExtensions();
        $allowedMimes = $constraints->allowedMimeTypes();
        $disallowedExt = array_map('strtolower', (array) config('image-pipeline.disallowed_extensions', []));
        $disallowedMimes = array_map('strtolower', (array) config('image-pipeline.disallowed_mimes', []));
        $maxMegapixels = $constraints->maxMegapixels;

        return [
            'avatar' => [
                'bail',
                'required',
                'file',

                // 1) Imagen y tamaño (KB). File::image() equivale al rule 'image' + chequeos MIME.
                File::image()->max($maxKb)->types($allowedExt),

                // 2) MIME real (sin espacios “fantasma”).
                'mimetypes:' . implode(',', $allowedMimes),

                // 3) Dimensiones razonables (guard rápido).
                "dimensions:min_width={$minDim},min_height={$minDim},max_width={$maxDim},max_height={$maxDim}",

                // 4) Validación profunda (firma real, EXIF, poliglot, megapíxeles, etc.).
                //    Pásale límites para que no dupliques números mágicos.
                new SecureImageValidation(
                    maxFileSizeBytes: $maxBytes,
                    normalize: true,
                    constraints: $constraints
                ),

                // 5) Reglas rápidas para bloquear SVG/ZIP y megapíxeles excesivos antes de procesos caros.
                static function (string $attribute, mixed $value, Closure $fail) use (
                    $disallowedExt,
                    $disallowedMimes,
                    $maxMegapixels
                ): void {
                    if (!$value instanceof UploadedFile) {
                        return;
                    }

                    $mime = strtolower((string) $value->getMimeType());
                    if ($mime !== '' && in_array($mime, $disallowedMimes, true)) {
                        $fail(__('image-pipeline.validation.avatar_mime'));
                        return;
                    }

                    $ext = strtolower((string) $value->getClientOriginalExtension());
                    if ($ext !== '' && in_array($ext, $disallowedExt, true)) {
                        $fail(__('image-pipeline.validation.avatar_mime'));
                        return;
                    }

                    $path = $value->getRealPath();
                    if ($path !== false) {
                        $info = @getimagesize($path);
                        if (is_array($info) && isset($info[0], $info[1])) {
                            $megapixels = ($info[0] * $info[1]) / 1_000_000;
                            if ($megapixels > $maxMegapixels) {
                                $fail(__('file-constraints.megapixels_exceeded', ['max' => $maxMegapixels]));
                            }
                        }
                    }
                },
            ],
        ];
    }

    /**
     * Mensajes i18n.
     */
    public function messages(): array
    {
        return [
            'avatar.required'   => __('image-pipeline.validation.avatar_required'),
            'avatar.file'       => __('image-pipeline.validation.avatar_file'),
            'avatar.mimetypes'  => __('image-pipeline.validation.avatar_mime'),
            'avatar.dimensions' => __('image-pipeline.validation.dimensions'),
        ];
    }
}
