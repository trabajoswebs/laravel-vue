<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Services\Upload\Contracts\UploadMetadata;
use App\Services\Upload\Contracts\UploadResult;
use App\Services\Upload\Contracts\UploadService;
use App\Services\Upload\Core\QuarantineRepository;
use App\Services\Upload\Exceptions\NormalizationFailedException;
use App\Services\Upload\Exceptions\QuarantineException;
use App\Services\Upload\Exceptions\ScanFailedException;
use App\Services\Upload\Exceptions\UploadException;
use App\Services\Upload\Exceptions\UploadValidationException;
use App\Services\Upload\Exceptions\UnexpectedUploadException;
use App\Services\Upload\Exceptions\VirusDetectedException;
use Closure;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia as HasMediaContract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Implementación por defecto que delega cada etapa de subida en dependencias explícitas.
 *
 * Mantiene cada paso puro aceptando/parámetros y devolviendo artefactos,
 * evitando estado global entre etapas.
 *
 * Nota: el límite duro de subida es 25 MB, por lo que trabajar con strings en memoria
 * resulta aceptable actualmente. La implementación mantiene métodos acotados para
 * facilitar una futura transición a streaming si el límite aumenta.
 */
final class DefaultUploadService implements UploadService
{
    public function __construct(
        private readonly QuarantineRepository $quarantine,
        private readonly ?Closure $scanCallback = null,
        private readonly ?Closure $validationCallback = null,
        private readonly ?Closure $normalizerCallback = null,
    ) {
    }

    /**
     * Almacena el archivo subido en cuarentena.
     *
     * @param UploadedFile $file Archivo subido.
     * @return string ID del archivo en cuarentena.
     * @throws QuarantineException Si el archivo no es válido o no se puede leer.
     */
    public function storeToQuarantine(UploadedFile $file): string
    {
        // Verifica si el archivo es válido
        if (!$file->isValid()) {
            throw new QuarantineException('Uploaded file is not valid.');
        }

        // Obtiene la ruta real del archivo
        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '') {
            throw new QuarantineException('Unable to obtain path from uploaded file.');
        }

        // Lectura completa justificada por el límite de 25 MB; fácil de adaptar a streams más adelante.
        $contents = @file_get_contents($realPath);
        if ($contents === false) {
            throw new QuarantineException('Unable to read uploaded file contents.');
        }

        // Guarda el contenido en cuarentena y retorna el ID
        return $this->quarantine->put($contents);
    }

    /**
     * Escanea el contenido del archivo en busca de amenazas.
     *
     * @param string $bytes Contenido del archivo como cadena de bytes.
     * @throws VirusDetectedException Si se detecta un virus.
     * @throws ScanFailedException Si falla el escaneo.
     */
    public function scan(string $bytes): void
    {
        // Ejecuta el callback de escaneo si está definido
        if (!$this->scanCallback instanceof Closure) {
            return;
        }

        try {
            ($this->scanCallback)($bytes);
        } catch (VirusDetectedException|ScanFailedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ScanFailedException('Scan callback failed during upload.', previous: $exception);
        }
    }

    /**
     * Valida el contenido del archivo.
     *
     * @param string $bytes Contenido del archivo como cadena de bytes.
     * @throws UploadValidationException Si falla la validación.
     */
    public function validate(string $bytes): void
    {
        // Ejecuta el callback de validación si está definido
        if (!$this->validationCallback instanceof Closure) {
            return;
        }

        try {
            ($this->validationCallback)($bytes);
        } catch (UploadValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new UploadValidationException('Validation callback failed during upload.', previous: $exception);
        }
    }

    /**
     * Normaliza el contenido del archivo.
     *
     * @param string $bytes Contenido del archivo como cadena de bytes.
     * @return string Contenido normalizado.
     * @throws NormalizationFailedException Si falla la normalización.
     */
    public function normalize(string $bytes): string
    {
        // Ejecuta el callback de normalización si está definido
        if (!$this->normalizerCallback instanceof Closure) {
            return $bytes;
        }

        try {
            $result = ($this->normalizerCallback)($bytes);
        } catch (NormalizationFailedException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new NormalizationFailedException('Normalization callback failed during upload.', $exception);
        }

        // Verifica que el resultado sea una cadena
        if (!is_string($result)) {
            throw new NormalizationFailedException('Normalization callback must return a string.');
        }

        return $result;
    }

    /**
     * Adjunta el archivo procesado a una entidad que implementa HasMedia.
     *
     * @param HasMediaContract $owner Entidad propietaria del archivo.
     * @param UploadResult $artifact Resultado del proceso de subida.
     * @param string $profile Perfil de la colección de medios.
     * @param string|null $disk Disco opcional para la colección.
     * @param bool $singleFile Indicador de colección de archivo único.
     * @throws UploadException Si falla la operación de adjuntar el archivo.
     */
    public function attach(
        HasMediaContract $owner,
        UploadResult $artifact,
        string $profile,
        ?string $disk = null,
        bool $singleFile = false
    ): Media {
        $metadata = $artifact->metadata;

        $fileName = $this->buildFileName($metadata, $profile);
        $headers = [
            'ACL' => 'private',
            'ContentType' => $metadata->mime,
            'ContentDisposition' => sprintf('inline; filename="%s"', $fileName),
        ];

        $adder = $owner->addMedia($artifact->path)
            ->usingFileName($fileName)
            ->addCustomHeaders($headers)
            ->withCustomProperties([
                'version' => $metadata->hash,
                'uploaded_at' => now()->toIso8601String(),
                'mime_type' => $metadata->mime,
                'width' => $metadata->dimensions['width'] ?? null,
                'height' => $metadata->dimensions['height'] ?? null,
                'original_filename' => $metadata->originalFilename,
                'quarantine_id' => $artifact->quarantineId,
                'headers' => $headers,
                'size' => $artifact->size,
            ]);

        if ($singleFile && method_exists($adder, 'singleFile')) {
            $adder->singleFile();
        }

        try {
            $media = $disk !== null && $disk !== ''
                ? $adder->toMediaCollection($profile, $disk)
                : $adder->toMediaCollection($profile);

            $this->cleanupArtifact($artifact);

            return $media;
        } catch (\Throwable $exception) {
            $this->cleanupArtifact($artifact);
            throw UploadException::fromThrowable('Unable to attach upload to media collection.', $exception);
        }
    }

    /**
     * Construye el nombre del archivo basado en metadatos y perfil.
     *
     * @param UploadMetadata $metadata Metadatos del archivo.
     * @param string $profile Perfil de la colección de medios.
     * @return string Nombre del archivo generado.
     */
    private function buildFileName(UploadMetadata $metadata, string $profile): string
    {
        $safeProfile = $this->sanitizeProfile($profile);
        $extension = $this->sanitizeExtension($metadata->extension ?? 'bin');
        $identifier = $metadata->hash ?? $this->generateSecureIdentifier();

        // Genera el nombre del archivo en formato: perfil-identificador.extension
        return sprintf('%s-%s.%s', $safeProfile, $identifier, $extension);
    }

    /**
     * Sanitiza el nombre del perfil para evitar caracteres no válidos.
     *
     * @param string $profile Nombre del perfil.
     * @return string Nombre del perfil sanitizado.
     */
    private function sanitizeProfile(string $profile): string
    {
        $normalized = strtolower($profile);
        $normalized = preg_replace('/[^a-z0-9_-]/', '-', $normalized) ?? 'upload';
        $normalized = trim($normalized, '-_');
        if ($normalized === '') {
            $normalized = 'upload';
        }

        return substr($normalized, 0, 40);
    }

    /**
     * Sanitiza la extensión del archivo para evitar caracteres no válidos.
     *
     * @param string $extension Extensión del archivo.
     * @return string Extensión sanitizada.
     */
    private function sanitizeExtension(string $extension): string
    {
        $clean = strtolower($extension);
        $clean = preg_replace('/[^a-z0-9]/', '', $clean) ?? 'bin';

        return $clean === '' ? 'bin' : substr($clean, 0, 10);
    }

    /**
     * Genera un identificador seguro para el archivo.
     *
     * @return string Identificador generado.
     * @throws UnexpectedUploadException Si no se puede generar el identificador.
     */
    private function generateSecureIdentifier(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new UnexpectedUploadException('Unable to generate secure file identifier.', previous: $exception);
        }
    }

    /**
     * Limpia los archivos temporales y de cuarentena si la operación falla.
     *
     * @param UploadResult $artifact Resultado del proceso de subida.
     */
    private function cleanupArtifact(UploadResult $artifact): void
    {
        // Elimina el archivo temporal si existe
        if ($artifact->path !== '' && is_file($artifact->path)) {
            @unlink($artifact->path);
        }

        // Elimina el archivo de cuarentena si existe
        if ($artifact->quarantineId) {
            try {
                $this->quarantine->delete($artifact->quarantineId);
            } catch (\Throwable $cleanupException) {
                logger()->warning('upload.quarantine.cleanup_failed', [
                    'path' => $artifact->quarantineId,
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }
    }
}
