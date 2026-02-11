<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Support;

use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Pipeline\DTO\InternalPipelineResult;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineState;
use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineToken;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Infrastructure\Uploads\Pipeline\Security\MimeNormalizer;
use Illuminate\Http\UploadedFile;
use App\Support\Logging\SecurityLogger;
use Illuminate\Support\Str;

/**
 * Coordina operaciones de cuarentena para subidas.
 */
final class QuarantineManager
{
    public function __construct(
        private readonly QuarantineRepository $quarantine,
    ) {}

    /**
     * Reconstruye un token de cuarentena a partir de su identificador relativo.
     *
     * @param string $identifier Identificador opaco devuelto tras la subida
     * @return QuarantineToken|null Token reconstruido o null si no se puede resolver
     */
    public function resolveToken(string $identifier): ?QuarantineToken
    {
        return $this->quarantine->resolveTokenByIdentifier($identifier);
    }

    /**
     * Valida el MIME contra listas permitidas/prohibidas.
     *
     * @param UploadedFile $file Archivo recibido.
     * @param MediaProfile|null $profile Perfil opcional para usar sus restricciones.
     */
    public function validateMimeType(UploadedFile $file, ?MediaProfile $profile = null): void
    {
        $constraints = $profile?->fileConstraints() ?? app(FileConstraints::class);
        $rawAllowedMimes = $constraints->allowedMimeTypes();
        $allowedMimes = $this->normalizeMimes($rawAllowedMimes);
        $disallowedMimes = $this->normalizeMimes((array) config('image-pipeline.disallowed_mimes', []));
        $mime = MimeNormalizer::normalize($file->getMimeType());

        if (!is_string($mime) || $mime === '') {
            throw new UploadValidationException(__('media.uploads.invalid_image'));
        }

        if ($rawAllowedMimes !== [] && $allowedMimes === []) {
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
     * @param UploadedFile $file Archivo a duplicar.
     * @param MediaProfile|null $profile Perfil asociado para TTL/validaciones.
     * @param string|null $correlationId Identificador de correlación opcional.
     * @param bool $validateMime Indica si se debe validar el MIME (true por defecto).
     * @return array{0:UploadedFile,1:QuarantineToken}
     */
    public function duplicate(UploadedFile $file, ?MediaProfile $profile = null, ?string $correlationId = null, bool $validateMime = true): array
    {
        $maxSize = (int) config('image-pipeline.max_upload_size', 25 * 1024 * 1024);
        // Fallback defensivo: si la configuración está en 0 o negativo, usa el valor por defecto (25 MB).
        if ($maxSize <= 0) {
            $maxSize = 25 * 1024 * 1024;
        }
        $size    = (int) $file->getSize();

        if ($size > 0 && $size > $maxSize) {
            throw new UploadValidationException(__('media.uploads.max_size_exceeded', ['bytes' => $maxSize]));
        }

        if ($validateMime) {
            $this->validateMimeType($file, $profile);
        }
        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_readable($realPath)) {
            throw new UploadValidationException(__('media.uploads.source_unreadable'));
        }

        $correlation = $correlationId !== null && trim((string) $correlationId) !== ''
            ? trim((string) $correlationId)
            : (string) Str::uuid();
        $pendingTtl = max(1, $profile?->getQuarantineTtlHours() ?? (int) config('image-pipeline.quarantine_pending_ttl_hours', 24));
        $failedTtl = max(1, $profile?->getFailedTtlHours() ?? (int) config('image-pipeline.quarantine_failed_ttl_hours', 4));
        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new UploadValidationException(__('media.uploads.source_unreadable'));
        }

        $name = $this->sanitizeOriginalName($file->getClientOriginalName());
        if ($name === '') {
            $name = basename($realPath) ?: 'upload.bin';
        }

        try {
            $token = $this->quarantine->putStream($handle, [
                'correlation_id' => $correlation,
                'profile' => $profile?->collection(),
                'pending_ttl_hours' => $pendingTtl,
                'failed_ttl_hours' => $failedTtl,
                'metadata' => [
                    'original_name' => $name,
                ],
            ]);
        } finally {
            fclose($handle);
        }

        $mime = $file->getMimeType() ?: null;

        $quarantined = new UploadedFile($token->path, $name, $mime, $file->getError(), true);

        return [$quarantined, $token];
    }

    /**
     * Transiciona el artefacto de cuarentena de forma segura.
     */
    public function transition(QuarantineToken $token, QuarantineState $from, QuarantineState $to, array $metadata = []): void
    {
        $this->quarantine->transition($token, $from, $to, $metadata);
    }

    /**
     * Devuelve el estado actual del artefacto.
     */
    public function getState(QuarantineToken $token): QuarantineState
    {
        return $this->quarantine->getState($token);
    }

    /**
     * Elimina un path de cuarentena si existe.
     */
    public function delete(QuarantineToken|string|null $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $this->quarantine->delete($path);
        } catch (\Throwable $exception) {
            $pathValue = $path instanceof QuarantineToken ? $path->path : (string) $path;
            SecurityLogger::warning('image_upload.quarantine_cleanup_failed', [
                'path_hash' => substr(hash('sha256', $pathValue), 0, 16),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Limpia artefactos temporales y cuarentena asociados a un InternalPipelineResult.
     *
     * @param InternalPipelineResult|null $artifact Artefacto a limpiar.
     * @param bool $removeQuarantine Indica si se debe eliminar también la cuarentena.
     */
    public function cleanupArtifact(?InternalPipelineResult $artifact, bool $removeQuarantine = true): void
    {
        if ($artifact === null) {
            return;
        }

        if ($artifact->path !== '' && is_file($artifact->path)) {
            if (! @unlink($artifact->path)) {
                SecurityLogger::warning('image_upload.temp_cleanup_failed', [
                    'path_hash' => substr(hash('sha256', $artifact->path), 0, 16),
                ]);
            }
        }

        if ($removeQuarantine && $artifact->quarantineId !== null) {
            $this->delete($artifact->quarantineId);
        }
    }

    private function sanitizeOriginalName(mixed $name): string
    {
        if (! is_string($name)) {
            return '';
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        return str_replace(["\r", "\n", "\0"], '', basename($trimmed));
    }

    /**
     * @param list<string> $mimes
     * @return list<string>
     */
    private function normalizeMimes(array $mimes): array
    {
        $normalized = [];
        foreach ($mimes as $mime) {
            $canonical = MimeNormalizer::normalize($mime);
            if ($canonical !== null) {
                $normalized[] = $canonical;
            }
        }

        return array_values(array_unique($normalized));
    }
}
