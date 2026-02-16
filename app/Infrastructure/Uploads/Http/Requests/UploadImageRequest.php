<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use App\Infrastructure\Uploads\Http\Requests\Concerns\UsesImageValidation; // Trait de reglas compartidas

/**
 * FormRequest genérico para subir imágenes a distintas colecciones.
 *
 * Lee límites globales desde FileConstraints/config y combina:
 * - File::image() + max(KB) + types(extensiones permitidas).
 * - mimetypes reales permitidos.
 * - dimensions (guard rápido).
 * - SecureImageValidation (magic bytes, image bombs, payloads).
 */
class UploadImageRequest extends FormRequest
{
    use UsesImageValidation; // Reutiliza reglas compartidas

    /** @inheritDoc */
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user') ?? $actor;

        if (!($actor instanceof User) || !($target instanceof User)) {
            return false;
        }

        return $actor->can('updateAvatar', $target);
    }

    /** @inheritDoc */
    public function rules(): array
    {
        return [
            'image' => $this->imageRules('image'), // Reutiliza reglas compartidas
        ];
    }
}
