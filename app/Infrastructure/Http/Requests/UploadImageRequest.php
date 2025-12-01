<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Requests;

use App\Infrastructure\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use App\Infrastructure\Http\Rules\SecureImageValidation;
use App\Application\Media\Contracts\FileConstraints as FC;

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
        /** @var FC $constraints */
        $constraints = app(FC::class);

        $maxBytes = $constraints->maxBytes;
        $maxKb    = (int) ceil($maxBytes / 1024);

        $minDim = $constraints->minDimension;
        $maxDim = $constraints->maxDimension;

        $ext   = $constraints->allowedExtensions();
        $mimes = $constraints->allowedMimeTypes();

        return [
            'image' => [
                'bail', 'required', 'file',
                File::image()->max($maxKb)->types($ext),
                'mimetypes:' . implode(',', $mimes),
                "dimensions:min_width={$minDim},min_height={$minDim},max_width={$maxDim},max_height={$maxDim}",
                new SecureImageValidation(
                    maxFileSizeBytes: $maxBytes,
                    constraints: $constraints
                ),
            ],
        ];
    }
}
