<?php // Trait para validar documentos (PDF/XLSX/CSV) sin parsing pesado

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests\Concerns;

use App\Domain\Uploads\UploadProfile;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Symfony\Component\Mime\MimeTypes;

/**
 * Expone reglas defensivas para uploads de documentos.
 */
trait UsesDocumentValidation
{
    /**
     * Construye reglas ligeras para documentos (PDF/XLSX/CSV, etc.).
     *
     * - Valida tamaño máximo en bytes.
     * - Usa mimetypes reales (no solo extensión).
     * - Fija extensiones válidas derivadas de los MIME permitidos.
     */
    protected function documentRules(string $field, UploadProfile $profile): array
    {
        $allowedMimes = $this->normalizeMimes($profile->allowedMimes);
        $extensions = $this->extensionsFor($allowedMimes);
        $maxBytes = max(1, (int) $profile->maxBytes);
        $maxKb = (int) ceil($maxBytes / 1024);

        return [
            'bail',
            'required',
            'file',
            File::types($extensions)->max($maxKb)->min(1),
            'mimetypes:' . implode(',', $allowedMimes),
            $this->guardDocument($allowedMimes, $maxBytes),
        ];
    }

    /**
     * @param list<string> $allowedMimes
     */
    private function guardDocument(array $allowedMimes, int $maxBytes): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowedMimes, $maxBytes): void {
            if (!$value instanceof UploadedFile) {
                return;
            }

            $size = $value->getSize();
            if ($size !== null && $size > $maxBytes) {
                $fail(__('validation.max.file', ['max' => (int) ceil($maxBytes / 1024)]));
                return;
            }

            $detected = strtolower((string) $value->getMimeType());
            if ($detected !== '' && !in_array($detected, $allowedMimes, true)) {
                $fail(__('validation.mimetypes', ['attribute' => $attribute]));
            }
        };
    }

    /**
     * @param list<string> $allowedMimes
     * @return list<string>
     */
    private function extensionsFor(array $allowedMimes): array
    {
        $mimeTypes = new MimeTypes();
        $extensions = [];

        foreach ($allowedMimes as $mime) {
            $extensions = array_merge($extensions, $mimeTypes->getExtensions($mime));
        }

        $normalized = array_values(array_unique(array_filter($extensions, 'is_string')));

        return array_map('strtolower', $normalized);
    }

    /**
     * @param list<mixed> $mimes
     * @return list<string>
     */
    private function normalizeMimes(array $mimes): array
    {
        $normalized = [];

        foreach ($mimes as $mime) {
            if (!is_string($mime)) {
                continue;
            }

            $value = strtolower(trim($mime));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
