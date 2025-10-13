<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use App\Rules\SecureImageValidation;
use App\Support\Media\ConversionProfiles\FileConstraints as FC;

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
        return $this->user() !== null;
    }

    /** @inheritDoc */
    public function rules(): array
    {
        $maxBytes = (int) (config('image-pipeline.max_bytes') ?? 5 * 1024 * 1024);
        $maxKb = (int) ceil($maxBytes / 1024);

        $minW = FC::MIN_WIDTH;  $minH = FC::MIN_HEIGHT;
        $maxW = FC::MAX_WIDTH;  $maxH = FC::MAX_HEIGHT;

        $ext   = FC::ALLOWED_EXTENSIONS;
        $mimes = FC::ALLOWED_MIME_TYPES;

        return [
            'image' => [
                'bail', 'required', 'file',
                File::image()->max($maxKb)->types($ext),
                'mimetypes:' . implode(',', $mimes),
                "dimensions:min_width={$minW},min_height={$minH},max_width={$maxW},max_height={$maxH}",
                new SecureImageValidation(maxFileSizeBytes: $maxBytes),
            ],
        ];
    }
}

