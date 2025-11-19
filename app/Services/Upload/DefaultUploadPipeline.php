<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Services\Upload\Contracts\UploadMetadata;
use App\Services\Upload\Contracts\UploadPipeline;
use App\Services\Upload\Contracts\UploadResult;
use App\Services\Upload\Exceptions\UploadException;
use Illuminate\Http\UploadedFile;
use SplFileObject;
use Throwable;

/**
 * Pipeline de ejemplo que analiza y normaliza archivos sin cargarlos completamente en memoria.
 */
final class DefaultUploadPipeline implements UploadPipeline
{
    private const CHUNK_SIZE = 131_072; // 128KB
    private const MAX_DIMENSION = 10_000;
    private const MAX_MEGAPIXELS = 40_000_000;

    /**
     * @param array<int, string> $allowedMimeTypes Tipos MIME permitidos.
     * @param array<int, string> $allowedExtensions Extensiones permitidas.
     */
    public function __construct(
        private readonly string $workingDirectory,
        private readonly array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'],
        private readonly array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'],
        private readonly int $maxFileSize = 25 * 1024 * 1024, // 25MB
    ) {
        $this->ensureWorkingDirectory();
    }

    /**
     * @inheritDoc
     */
    public function process(UploadedFile|SplFileObject|string $source): UploadResult
    {
        $normalizedPath = null;
        $originalFilename = $this->resolveOriginalFilename($source);

        try {
            // Resuelve el archivo como un SplFileObject
            $file = $this->resolveFileObject($source);
            // Valida propiedades del archivo (tamaño, tipo, etc.)
            $baselineHash = $this->validateFileProperties($file, $originalFilename);

            // Analiza el archivo en bloques para seguridad
            $this->analyze($file);
            // Verifica que el archivo no haya cambiado durante el proceso
            $this->ensureChecksumUnchanged($file, $baselineHash);
            // Normaliza el archivo (limpieza, metadata, etc.)
            $normalizedPath = $this->normalize($file);

            // Extrae información del archivo normalizado
            $size = $this->safeFilesize($normalizedPath);
            $mime = $this->detectMime($normalizedPath);
            // Si es una imagen, intenta leer dimensiones
            $dimensions = $this->isImageMime($mime) ? $this->tryReadDimensions($normalizedPath) : null;

            // Crea metadatos del archivo
            $metadata = new UploadMetadata(
                mime: $mime,
                extension: $this->guessExtension($normalizedPath, $originalFilename, $mime),
                hash: hash_file('sha256', $normalizedPath),
                dimensions: $dimensions,
                originalFilename: $originalFilename,
            );

            // Retorna el resultado del proceso de subida
            return new UploadResult(
                path: $normalizedPath,
                size: $size,
                metadata: $metadata,
                quarantineId: $this->resolveQuarantineReference($source),
            );
        } catch (Throwable $exception) {
            // Elimina el archivo temporal si fue creado
            if (is_string($normalizedPath)) {
                $this->deleteFileSilently($normalizedPath);
            }

            // Lanza excepción original si es UploadException
            if ($exception instanceof UploadException) {
                throw $exception;
            }

            throw UploadException::fromThrowable('Upload pipeline failed.', $exception);
        }
    }

    /**
     * Resuelve el archivo a un SplFileObject.
     *
     * @param UploadedFile|SplFileObject|string $source Origen del archivo.
     * @return SplFileObject El archivo como SplFileObject.
     */
    private function resolveFileObject(UploadedFile|SplFileObject|string $source): SplFileObject
    {
        if ($source instanceof SplFileObject) {
            $source->rewind();
            return $source;
        }

        $path = match (true) {
            $source instanceof UploadedFile => $source->getRealPath(),
            default => $source,
        };

        if (!is_string($path) || $path === '' || !is_file($path)) {
            throw new UploadException('Upload source is not readable.');
        }

        $file = new SplFileObject($path, 'rb');
        $file->rewind();

        return $file;
    }

    /**
     * Analiza el archivo en bloques para detectar código malicioso.
     *
     * @param SplFileObject $file Archivo a analizar.
     */
    private function analyze(SplFileObject $file): void
    {
        $file->rewind();

        while (!$file->eof()) {
            $chunk = $file->fread(self::CHUNK_SIZE);
            if ($chunk === '' || $chunk === false) {
                continue;
            }

            // Ejecuta verificaciones defensivas en el bloque
            $this->runDefensiveChecks($chunk);
        }

        $file->rewind();
    }

    /**
     * Normaliza el archivo copiando su contenido bloque a bloque.
     *
     * @param SplFileObject $file Archivo a normalizar.
     * @return string Ruta del archivo normalizado.
     */
    private function normalize(SplFileObject $file): string
    {
        $file->rewind();
        $target = $this->createWorkingFile(); // Crea archivo temporal
        $destination = null;

        try {
            $destination = new SplFileObject($target, 'wb');

            while (!$file->eof()) {
                $chunk = $file->fread(self::CHUNK_SIZE);
                if ($chunk === '' || $chunk === false) {
                    continue;
                }

                // Aplica pasos de normalización al bloque
                $destination->fwrite($this->applyNormalizationSteps($chunk));
            }

            $destination = null;
            $file->rewind();

            return $target;
        } catch (Throwable $exception) {
            $destination = null;
            $this->deleteFileSilently($target);

            throw UploadException::fromThrowable('Unable to normalize uploaded file.', $exception);
        }
    }

    /**
     * Ejecuta verificaciones defensivas en un bloque de archivo.
     *
     * @param string $chunk Bloque del archivo.
     */
    private function runDefensiveChecks(string $chunk): void
    {
        if (preg_match('/<\?(?:php|=)?/i', $chunk) === 1) {
            throw new UploadException('Executable code detected inside the upload.');
        }

        if (preg_match('/<script\b/i', $chunk) === 1) {
            throw new UploadException('HTML script tags are not allowed inside binary uploads.');
        }

        if (preg_match('/\b(?:eval|system|exec|passthru|shell_exec)\s*\(/i', $chunk) === 1) {
            throw new UploadException('Dangerous functions detected inside the upload.');
        }
    }

    /**
     * Aplica pasos de normalización a un bloque del archivo.
     *
     * @param string $chunk Bloque del archivo.
     * @return string Bloque normalizado.
     */
    private function applyNormalizationSteps(string $chunk): string
    {
        // Punto de extensión para limpiar metadata binaria, EXIF, etc.
        return $chunk;
    }

    /**
     * Crea un archivo temporal en el directorio de trabajo.
     *
     * @return string Ruta del archivo temporal.
     */
    private function createWorkingFile(): string
    {
        $path = tempnam($this->workingDirectory, 'upload_');
        if ($path === false) {
            throw new UploadException('Unable to allocate working file.');
        }

        return $path;
    }

    /**
     * Detecta el tipo MIME del archivo.
     *
     * @param string $path Ruta del archivo.
     * @return string Tipo MIME detectado.
     */
    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        try {
            $mime = finfo_file($finfo, $path) ?: 'application/octet-stream';
        } finally {
            finfo_close($finfo);
        }

        return $mime;
    }

    /**
     * Intenta leer las dimensiones de una imagen.
     *
     * @param string $path Ruta del archivo de imagen.
     * @return array|null Dimensiones [width, height] o null si falla.
     */
    private function tryReadDimensions(string $path): ?array
    {
        try {
            $info = @getimagesize($path);
            if (is_array($info) && isset($info[0], $info[1])) {
                return [
                    'width' => (int) $info[0],
                    'height' => (int) $info[1],
                ];
            }
        } catch (Throwable) {
            // Ignorar y retornar null.
        }

        return null;
    }

    /**
     * Resuelve el nombre original del archivo.
     *
     * @param UploadedFile|SplFileObject|string $source Origen del archivo.
     * @return string|null Nombre original o null si no se puede resolver.
     */
    private function resolveOriginalFilename(UploadedFile|SplFileObject|string $source): ?string
    {
        if ($source instanceof UploadedFile) {
            $name = $source->getClientOriginalName();

            return $name === '' ? null : $name;
        }

        if ($source instanceof SplFileObject) {
            $path = $source->getRealPath();

            return $path ? basename($path) : null;
        }

        if (is_string($source) && $source !== '') {
            return basename($source);
        }

        return null;
    }

    /**
     * Resuelve una referencia de cuarentena para el archivo.
     *
     * @param UploadedFile|SplFileObject|string $source Origen del archivo.
     * @return string|null Ruta original o null si no se puede resolver.
     */
    private function resolveQuarantineReference(UploadedFile|SplFileObject|string $source): ?string
    {
        if ($source instanceof UploadedFile) {
            return $source->getRealPath() ?: null;
        }

        if ($source instanceof SplFileObject) {
            return $source->getRealPath() ?: null;
        }

        if (is_string($source) && str_contains($source, DIRECTORY_SEPARATOR)) {
            return $source;
        }

        return null;
    }

    /**
     * Obtiene el tamaño del archivo de forma segura.
     *
     * @param string $path Ruta del archivo.
     * @return int Tamaño del archivo.
     */
    private function safeFilesize(string $path): int
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new UploadException('Unable to determine file size.');
        }

        return (int) $size;
    }

    /**
     * Asegura que el directorio de trabajo exista y sea escribible.
     */
    private function ensureWorkingDirectory(): void
    {
        if (is_dir($this->workingDirectory)) {
            return;
        }

        if (@mkdir($this->workingDirectory, 0775, true) === false && !is_dir($this->workingDirectory)) {
            throw new UploadException('Working directory is not writable.');
        }
    }

    /**
     * Elimina un archivo de forma silenciosa.
     *
     * @param string $path Ruta del archivo a eliminar.
     */
    private function deleteFileSilently(string $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Valida las propiedades del archivo (tamaño, tipo, etc.).
     *
     * @param SplFileObject $file Archivo a validar.
     * @param string|null $originalFilename Nombre original del archivo.
     * @return string Hash SHA256 del archivo original.
     */
    private function validateFileProperties(SplFileObject $file, ?string $originalFilename): string
    {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new UploadException('Unable to resolve upload path.');
        }

        $size = $this->safeFilesize($path);
        if ($size === 0) {
            throw new UploadException('Uploaded file is empty.');
        }

        if ($size > $this->maxFileSize) {
            throw new UploadException('Uploaded file exceeds the allowed size.');
        }

        $mime = $this->detectMime($path);
        if ($this->allowedMimeTypes !== [] && !in_array($mime, $this->allowedMimeTypes, true)) {
            throw new UploadException("MIME type {$mime} is not allowed.");
        }

        // Valida la firma binaria del archivo
        $this->validateMagicBytes($file, $mime);

        $extension = strtolower((string) pathinfo($originalFilename ?? $path, PATHINFO_EXTENSION));
        if ($extension !== '' && $this->allowedExtensions !== [] && !in_array($extension, $this->allowedExtensions, true)) {
            throw new UploadException("Extension .{$extension} is not permitted.");
        }

        if ($this->isImageMime($mime)) {
            // Protege contra imágenes con dimensiones excesivas
            $this->guardImageDimensions($path);
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new UploadException('Unable to hash uploaded file.');
        }

        return $hash;
    }

    /**
     * Verifica si el tipo MIME es de imagen.
     *
     * @param string $mime Tipo MIME.
     * @return bool Verdadero si es una imagen.
     */
    private function isImageMime(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /**
     * Adivina la extensión del archivo basado en nombre original o MIME.
     *
     * @param string $path Ruta del archivo normalizado.
     * @param string|null $originalFilename Nombre original del archivo.
     * @param string $mime Tipo MIME detectado.
     * @return string|null Extensión o null si no se puede determinar.
     */
    private function guessExtension(string $path, ?string $originalFilename, string $mime): ?string
    {
        $candidate = strtolower((string) pathinfo($originalFilename ?? $path, PATHINFO_EXTENSION));
        if ($candidate !== '') {
            return $candidate;
        }

        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        return $map[$mime] ?? null;
    }

    /**
     * Valida la firma binaria del archivo.
     *
     * @param SplFileObject $file Archivo a validar.
     * @param string $mime Tipo MIME del archivo.
     */
    private function validateMagicBytes(SplFileObject $file, string $mime): void
    {
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89PNG\r\n\x1a\n"],
            'image/webp' => ["RIFF"],
        ];

        if (!isset($signatures[$mime])) {
            return;
        }

        $file->rewind();
        $prefix = $file->fread(12);
        $file->rewind();

        if ($mime === 'image/webp') {
            if (!str_starts_with($prefix, 'RIFF') || substr($prefix, 8, 4) !== 'WEBP') {
                throw new UploadException('Unexpected binary signature for WebP file.');
            }

            return;
        }

        foreach ($signatures[$mime] as $signature) {
            if (str_starts_with($prefix, $signature)) {
                return;
            }
        }

        throw new UploadException('Unexpected binary signature for uploaded file.');
    }

    /**
     * Protege contra imágenes con dimensiones excesivas.
     *
     * @param string $path Ruta del archivo de imagen.
     */
    private function guardImageDimensions(string $path): void
    {
        $dimensions = $this->tryReadDimensions($path);
        if (!$dimensions) {
            return;
        }

        $width = $dimensions['width'];
        $height = $dimensions['height'];
        $megapixels = $width * $height;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $megapixels > self::MAX_MEGAPIXELS) {
            throw new UploadException('Image dimensions exceed the allowed limits.');
        }
    }

    /**
     * Asegura que el archivo no haya cambiado durante el procesamiento.
     *
     * @param SplFileObject $file Archivo original.
     * @param string $expectedHash Hash SHA256 esperado.
     */
    private function ensureChecksumUnchanged(SplFileObject $file, string $expectedHash): void
    {
        if ($expectedHash === '') {
            return;
        }

        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new UploadException('Unable to resolve upload path for integrity check.');
        }

        $current = hash_file('sha256', $path);
        if ($current === false || $current !== $expectedHash) {
            throw new UploadException('Upload content changed during processing.');
        }
    }
}