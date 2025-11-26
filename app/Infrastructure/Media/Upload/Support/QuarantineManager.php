<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Support;

use App\Infrastructure\Media\Upload\Contracts\UploadResult;
use App\Infrastructure\Media\Upload\Core\QuarantineRepository;
use App\Infrastructure\Media\Upload\Exceptions\UploadValidationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Coordina operaciones de cuarentena para subidas.
 */
final class QuarantineManager
{
    public function __construct(
        private readonly QuarantineRepository $quarantine,
    ) {}

    /**
     * Valida el MIME contra listas permitidas/prohibidas.
     */
    public function validateMimeType(UploadedFile $file): void
    {
        $allowedMimes    = array_keys((array) config('image-pipeline.allowed_mimes', []));
        $disallowedMimes = (array) config('image-pipeline.disallowed_mimes', []);
        $mime            = $file->getMimeType();

        if (!is_string($mime)) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        if (in_array($mime, $disallowedMimes, true)) {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }
    }

    /**
     * Duplica el archivo en la cuarentena y devuelve su UploadedFile y ruta.
     *
     * @return array{0:UploadedFile,1:string}
     */
    public function duplicate(UploadedFile $file): array
    {
        $maxSize = (int) config('image-pipeline.max_upload_size', 25 * 1024 * 1024);
        $size    = (int) $file->getSize();

        if ($size > 0 && $size > $maxSize) {
            throw new UploadValidationException(__('media.uploads.max_size_exceeded', ['bytes' => $maxSize]));
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_readable($realPath)) {
            throw new UploadValidationException(__('media.uploads.source_unreadable'));
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new UploadValidationException(__('media.uploads.source_unreadable'));
        }

        try {
            $path = $this->quarantine->putStream($handle);
        } finally {
            fclose($handle);
        }

        $name = $file->getClientOriginalName();
        if (!is_string($name) || $name === '') {
            $name = basename($path);
        }

        $mime = $file->getClientMimeType() ?? $file->getMimeType() ?: null;

        $quarantined = new UploadedFile($path, $name, $mime, $file->getError(), true);

        return [$quarantined, $path];
    }

    /**
     * Elimina un path de cuarentena si existe.
     */
    public function delete(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->quarantine->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('image_upload.quarantine_cleanup_failed', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Limpia artefactos temporales y cuarentena asociados a un UploadResult.
     */
    public function cleanupArtifact(?UploadResult $artifact): void
    {
        if ($artifact === null) {
            return;
        }

        if ($artifact->path !== '' && is_file($artifact->path)) {
            @unlink($artifact->path);
        }

        if ($artifact->quarantineId) {
            $this->delete($artifact->quarantineId);
        }
    }
}
