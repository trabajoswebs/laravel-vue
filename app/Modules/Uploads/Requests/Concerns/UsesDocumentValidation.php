<?php // Trait para validar documentos (PDF/XLSX/CSV) sin parsing pesado

declare(strict_types=1);

namespace App\Modules\Uploads\Requests\Concerns;

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
     * - En perfiles no-import, aplica MIME/extension defensivos.
     * - En imports, delega la validación de MIME/firma al guard de dominio para evitar falsos negativos.
     */
    protected function documentRules(string $field, UploadProfile $profile): array
    {
        $allowedMimes = $this->normalizeMimes($profile->allowedMimes);
        $maxBytes = max(1, (int) $profile->maxBytes);
        $maxKb = (int) ceil($maxBytes / 1024);

        if ($this->isImportProfile($profile)) {
            return [
                'bail',
                'required',
                'file',
                'max:' . $maxKb,
                $this->guardDocument($maxBytes),
            ];
        }

        $extensions = $this->extensionsFor($allowedMimes);

        return [
            'bail',
            'required',
            'file',
            File::types($extensions)->max($maxKb),
            'mimetypes:' . implode(',', $allowedMimes),
            $this->guardDocument($maxBytes),
        ];
    }

    private function guardDocument(int $maxBytes): Closure
    {
        return static function (string $_attribute, mixed $value, Closure $fail) use ($maxBytes): void {
            if (!$value instanceof UploadedFile) {
                return;
            }

            $size = $value->getSize();
            if ($size !== null && $size > $maxBytes) {
                $fail(__('validation.max.file', ['max' => (int) ceil($maxBytes / 1024)]));
            }
        };
    }

    private function isImportProfile(UploadProfile $profile): bool
    {
        return (string) $profile->pathCategory === 'imports';
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
