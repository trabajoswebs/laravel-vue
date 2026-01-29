<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesImageValidation; // Trait de reglas de imagen
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Solicitud para validar la actualización del avatar del usuario.
 * 
 * Incluye validación de tipo, tamaño, dimensiones, MIME y seguridad de la imagen.
 * La autorización fina se maneja en el Policy o Controller.
 */
class UpdateAvatarRequest extends FormRequest
{
    use UsesImageValidation; // Reutiliza reglas de imagen compartidas

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
        return [
            'avatar' => $this->imageRules('avatar'), // Reutiliza reglas compartidas
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

    protected function failedValidation(Validator $validator): void
    {
        if ($this->wantsJson()) {
            $errors = $validator->errors()->all();
            $first = $errors[0] ?? __('validation.custom.image.invalid_file');
            $code = 'VALIDATION_ERROR';

            if (str_contains($first, '|')) {
                [$maybeCode, $message] = explode('|', $first, 2);
                $code = trim($maybeCode) ?: $code;
                $first = trim($message) ?: $first;
            }

            throw new HttpResponseException(response()->json([
                'message' => $first,
                'code' => $code,
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
