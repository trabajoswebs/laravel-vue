<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload;

use App\Application\Media\Contracts\FileConstraints;
use App\Application\Media\Contracts\MediaProfile;
use App\Infrastructure\Media\Security\MagicBytesValidator;
use App\Infrastructure\Media\Security\Upload\UploadSecurityLogger;
use App\Infrastructure\Media\Upload\Contracts\UploadMetadata;
use App\Infrastructure\Media\Upload\Contracts\UploadPipeline;
use App\Infrastructure\Media\Upload\Contracts\UploadResult;
use App\Infrastructure\Media\Upload\Exceptions\UnexpectedUploadException;
use App\Infrastructure\Media\Upload\Exceptions\UploadException;
use App\Infrastructure\Media\Upload\Exceptions\UploadValidationException;
use App\Infrastructure\Media\Upload\ImageUploadPipelineAdapter;
use Illuminate\Http\UploadedFile;
use SplFileObject;
use Throwable;

/**
 * Pipeline de ejemplo que analiza y normaliza archivos sin cargarlos completamente en memoria.
 * 
 * Esta clase implementa un pipeline de subida de archivos que procesa los archivos
 * en bloques pequeños para evitar el uso excesivo de memoria y realizar validaciones
 * de seguridad sin cargar todo el archivo en memoria.
 */
final class DefaultUploadPipeline implements UploadPipeline
{
    // Tamaño del bloque para leer archivos (128KB)
    private const CHUNK_SIZE = 131_072;

    // Número de bytes para solapamiento en escaneo de bloques
    private const SCAN_OVERLAP = 512;

    /**
     * Buffer utilizada para buscar patrones entre chunks.
     * 
     * Permite detectar patrones peligrosos que podrían estar divididos
     * entre bloques consecutivos.
     */
    private string $scanBuffer = '';

    /**
     * Constructor del pipeline de subida.
     * 
     * @param string $workingDirectory Directorio temporal para archivos de trabajo
     * @param ImageUploadPipelineAdapter $imagePipeline Adaptador para procesamiento de imágenes
     * @param MagicBytesValidator $magicBytes Validador de firmas/magic bytes
     * @param UploadSecurityLogger $securityLogger Logger de eventos de seguridad
     */
    public function __construct(
        private readonly string $workingDirectory,
        private readonly ImageUploadPipelineAdapter $imagePipeline,
        private readonly MagicBytesValidator $magicBytes,
        private readonly UploadSecurityLogger $securityLogger,
    ) {
        $this->ensureWorkingDirectory();
    }

    /**
     * @inheritDoc
     * 
     * Procesa un archivo de subida aplicando validaciones y normalización.
     * 
     * Si el perfil requiere normalización de imagen, delega al adaptador de imagen.
     * De lo contrario, procesa el archivo en bloques para análisis de seguridad,
     * valida las firmas dentro del lock y registra eventos en el logger.
     */
    public function process(
        UploadedFile|SplFileObject|string $source,
        MediaProfile $profile,
        string $correlationId
    ): UploadResult {
        if ($profile->requiresImageNormalization()) {
            // Delegamos a ImagePipeline cuando el perfil exige normalización completa.
            return $this->imagePipeline->process($source, $profile, $correlationId);
        }

        $normalizedPath = null;
        $originalFilename = $this->resolveOriginalFilename($source);
        $securityContext = [
            'correlation_id' => $correlationId,
            'profile' => $profile->collection(),
            'filename' => $this->sanitizeFilenameForLog($originalFilename),
        ];

        try {
            $constraints = $profile->fileConstraints();

            // Resuelve el archivo como un SplFileObject
            $file = $this->resolveFileObject($source);

            $this->withSharedFileLock($file, function (SplFileObject $lockedFile) use ($originalFilename, $constraints): void {
                $baselineHash = $this->validateFileProperties($lockedFile, $originalFilename, $constraints);
                $this->analyze($lockedFile);
                $this->ensureChecksumUnchanged($lockedFile, $baselineHash);
            });
            // Valida magic bytes/anti-polyglot sobre la copia a procesar dentro del lock.
            $realPath = $file->getRealPath();
            if (!is_string($realPath) || $realPath === '') {
                throw new UploadValidationException('Unable to resolve upload path for magic bytes validation.');
            }
            $this->magicBytes->validate($realPath, $constraints, $securityContext);

            // Normaliza el archivo (limpieza, metadata, etc.)
            $normalizedPath = $this->normalize($file);
            $normalizedFile = new SplFileObject($normalizedPath, 'rb');
            $normalizedFile->rewind();
            $this->validateFileProperties($normalizedFile, $originalFilename, $constraints);

            // Extrae información del archivo normalizado
            $size = $this->safeFilesize($normalizedPath);
            $mime = $this->detectMime($normalizedPath);
            // Si es una imagen, intenta leer dimensiones
            $dimensions = $this->isImageMime($mime) ? $this->tryReadDimensions($normalizedPath) : null;
            $this->enforceDecompressionRatio($mime, $dimensions, $size, $constraints);

            // Crea metadatos del archivo
            $metadata = new UploadMetadata(
                mime: $mime,
                extension: $this->guessExtension($normalizedPath, $originalFilename, $mime, $constraints),
                hash: hash_file('sha256', $normalizedPath),
                dimensions: $dimensions,
                originalFilename: $originalFilename,
            );
            $this->securityLogger->normalized($securityContext + [
                'mime' => $mime,
                'size' => $size,
            ]);

            // Retorna el resultado del proceso de subida
            return new UploadResult(
                path: $normalizedPath,
                size: $size,
                metadata: $metadata,
            );
        } catch (Throwable $exception) {
            $this->securityLogger->validationFailed($securityContext + ['error' => $exception->getMessage()]);
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
            throw new UploadValidationException('Upload source is not readable.');
        }

        $file = new SplFileObject($path, 'rb');
        $file->rewind();

        return $file;
    }

    /**
     * Analiza el archivo en bloques para detectar código malicioso.
     *
     * Lee el archivo en bloques pequeños y busca patrones peligrosos
     * como código PHP, scripts o funciones peligrosas.
     *
     * @param SplFileObject $file Archivo a analizar.
     */
    private function analyze(SplFileObject $file): void
    {
        $file->rewind();
        $this->scanBuffer = '';

        while (!$file->eof()) {
            $chunk = $file->fread(self::CHUNK_SIZE);
            if ($chunk === '' || $chunk === false) {
                continue;
            }

            // Ejecuta verificaciones defensivas en el bloque
            $this->runDefensiveChecks($chunk);
        }

        $file->rewind();
        $this->scanBuffer = '';
    }

    /**
     * Normaliza el archivo copiando su contenido bloque a bloque.
     *
     * Crea un archivo temporal y copia el contenido original aplicando
     * pasos de normalización si es necesario.
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
     * Busca patrones peligrosos como código PHP, scripts o funciones
     * potencialmente peligrosas.
     *
     * @param string $chunk Bloque del archivo.
     */
    private function runDefensiveChecks(string $chunk): void
    {
        $window = $this->scanBuffer . $chunk;

        // Verifica si hay código PHP malicioso
        if (preg_match('/<\?[\s\x00]*(?:php|=)?/i', $window) === 1) {
            throw new UploadValidationException('Executable code detected inside the upload.');
        }

        // Verifica si hay etiquetas de script HTML
        if (preg_match('/<script\b/i', $window) === 1) {
            throw new UploadValidationException('HTML script tags are not allowed inside binary uploads.');
        }

        // Verifica si hay funciones peligrosas parcialmente ofuscadas
        if (preg_match('/\b(?:eval|system|exec|passthru|shell_exec|proc_open|popen|assert)\b(?:[\s\/\*\x00]+|\R)*\(/i', $window) === 1) {
            throw new UploadValidationException('Dangerous functions detected inside the upload.');
        }

        // Mantiene una ventana de solapamiento para detectar patrones que cruzan bloques
        $tailLength = min(self::SCAN_OVERLAP, strlen($window));
        $this->scanBuffer = $tailLength > 0 ? substr($window, -$tailLength) : '';
    }

    /**
     * Aplica pasos de normalización a un bloque del archivo.
     *
     * En el pipeline genérico no se transforman los bytes, sólo se copian para
     * mantener la estructura. Las validaciones previas/seguros sanearán los datos.
     *
     * @param string $chunk Bloque del archivo.
     * @return string Bloque normalizado (igual al original).
     */
    private function applyNormalizationSteps(string $chunk): string
    {
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
            throw new UnexpectedUploadException('Unable to allocate working file.');
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
     * Obtiene el tamaño del archivo de forma segura.
     *
     * @param string $path Ruta del archivo.
     * @return int Tamaño del archivo.
     */
    private function safeFilesize(string $path): int
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new UploadValidationException('Unable to determine file size.');
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
            throw new UnexpectedUploadException('Working directory is not writable.');
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
     * Obtiene un bloqueo compartido sobre el archivo para evitar TOCTOU.
     *
     * @param SplFileObject $file Archivo que se bloqueará en modo compartido.
     * @param callable(SplFileObject):void $callback Lógica que se ejecutará mientras está bloqueado.
     */
    private function withSharedFileLock(SplFileObject $file, callable $callback): void
    {
        if (!$file->flock(LOCK_SH)) {
            throw new UploadException('Unable to obtain shared lock on upload source.');
        }

        try {
            $callback($file);
        } finally {
            $file->flock(LOCK_UN);
            $file->rewind();
        }
    }

    /**
     * Valida las propiedades del archivo (tamaño, tipo, etc.).
     *
     * @param SplFileObject $file Archivo a validar.
     * @param string|null $originalFilename Nombre original del archivo.
     * @param FileConstraints $constraints Restricciones del perfil
     * @return string Hash SHA256 del archivo original.
     */
    private function validateFileProperties(
        SplFileObject $file,
        ?string $originalFilename,
        FileConstraints $constraints
    ): string {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new UploadValidationException('Unable to resolve upload path.');
        }

        $size = $this->safeFilesize($path);
        if ($size === 0) {
            throw new UploadValidationException('Uploaded file is empty.');
        }

        if ($size > $constraints->maxBytes) {
            throw new UploadValidationException('Uploaded file exceeds the allowed size.');
        }

        $mime = $this->detectMime($path);
        if ($constraints->allowedMimeTypes() !== [] && !in_array($mime, $constraints->allowedMimeTypes(), true)) {
            throw new UploadValidationException("MIME type {$mime} is not allowed.");
        }

        // Valida la firma binaria del archivo
        $this->validateMagicBytes($file, $mime);

        $extension = strtolower((string) pathinfo($originalFilename ?? $path, PATHINFO_EXTENSION));
        if ($extension !== '' && $constraints->allowedExtensions !== [] && !in_array($extension, $constraints->allowedExtensions, true)) {
            throw new UploadValidationException("Extension .{$extension} is not permitted.");
        }

        if ($this->isImageMime($mime)) {
            // Protege contra imágenes con dimensiones excesivas
            $this->guardImageDimensions($path, $constraints);
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new UploadValidationException('Unable to hash uploaded file.');
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
     * @param FileConstraints $constraints Restricciones del perfil
     * @return string|null Extensión o null si no se puede determinar.
     */
    private function guessExtension(
        string $path,
        ?string $originalFilename,
        string $mime,
        FileConstraints $constraints
    ): ?string {
        $candidate = strtolower((string) pathinfo($originalFilename ?? $path, PATHINFO_EXTENSION));
        if ($candidate !== '') {
            return $candidate;
        }

        $map = $constraints->allowedMimeMap();

        return $map[$mime] ?? null;
    }

    /**
     * Valida la firma binaria del archivo.
     *
     * Comprueba que el archivo tenga la firma binaria correcta
     * según su tipo MIME para evitar falsificaciones.
     *
     * @param SplFileObject $file Archivo a validar.
     * @param string $mime Tipo MIME del archivo.
     */
    private function validateMagicBytes(SplFileObject $file, string $mime): void
    {
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89PNG\r\n\x1a\n"],
            'image/gif' => ['GIF87a', 'GIF89a'],
        ];

        $file->rewind();
        $prefix = $file->fread(64);
        $file->rewind();

        if ($mime === 'image/webp') {
            if (!$this->isWebpSignature($prefix)) {
                throw new UploadValidationException('Unexpected binary signature for WebP file.');
            }

            return;
        }

        if ($mime === 'image/avif' && $this->isAvifSignature($prefix)) {
            return;
        }

        if (!isset($signatures[$mime])) {
            return;
        }

        foreach ($signatures[$mime] as $signature) {
            if (str_starts_with($prefix, $signature)) {
                return;
            }
        }

        throw new UploadValidationException('Unexpected binary signature for uploaded file.');
    }

    /**
     * Valida la cabecera WebP.
     */
    private function isWebpSignature(string $prefix): bool
    {
        if (!str_starts_with($prefix, 'RIFF')) {
            return false;
        }

        if (substr($prefix, 8, 4) !== 'WEBP') {
            return false;
        }

        $chunk = substr($prefix, 12, 4);
        return in_array($chunk, ['VP8 ', 'VP8L', 'VP8X'], true);
    }

    /**
     * Valida la cabecera AVIF.
     */
    private function isAvifSignature(string $prefix): bool
    {
        if (strlen($prefix) < 12) {
            return false;
        }

        if (substr($prefix, 4, 4) !== 'ftyp') {
            return false;
        }

        $brand = substr($prefix, 8, 4);
        return in_array($brand, ['avif', 'avis', 'mif1'], true);
    }

    /**
     * Protege contra imágenes con dimensiones excesivas.
     *
     * Verifica que las dimensiones de la imagen estén dentro de los límites
     * permitidos por las restricciones.
     *
     * @param string $path Ruta del archivo de imagen.
     * @param FileConstraints $constraints Restricciones de dimensiones
     */
    private function guardImageDimensions(string $path, FileConstraints $constraints): void
    {
        $dimensions = $this->tryReadDimensions($path);
        if (!$dimensions) {
            return;
        }

        $width = $dimensions['width'];
        $height = $dimensions['height'];
        $megapixels = $width * $height;

        if (
            $width < $constraints->minDimension ||
            $height < $constraints->minDimension ||
            $width > $constraints->maxDimension ||
            $height > $constraints->maxDimension ||
            $megapixels > ($constraints->maxMegapixels * 1_000_000)
        ) {
            throw new UploadValidationException('Image dimensions exceed the allowed limits.');
        }
    }

    /**
     * Aplica ratio de descompresión máximo para prevenir bombs.
     *
     * @param string $mime Mime detectado
     * @param array|null $dimensions Dimensiones si es imagen
     * @param int $size Bytes en disco
     * @param FileConstraints $constraints Restricciones configuradas
     */
    private function enforceDecompressionRatio(
        string $mime,
        ?array $dimensions,
        int $size,
        FileConstraints $constraints
    ): void {
        if ($constraints->maxDecompressionRatio === null || $size <= 0) {
            return;
        }

        if ($dimensions === null || !isset($dimensions['width'], $dimensions['height'])) {
            return;
        }

        $pixels = (int) $dimensions['width'] * (int) $dimensions['height'];
        if ($pixels <= 0) {
            return;
        }

        // Aproximamos bytes descomprimidos como RGBA por píxel.
        $estimatedBytes = $pixels * 4;
        $ratio = $estimatedBytes / max(1, $size);

        if ($ratio > $constraints->maxDecompressionRatio) {
            throw new UploadValidationException(sprintf(
                'Decompression ratio exceeds allowed limit (ratio: %.2f, limit: %.2f)',
                $ratio,
                $constraints->maxDecompressionRatio
            ));
        }
    }

    /**
     * Sanitiza nombres de archivo antes de loguear para evitar caracteres extraños.
     */
    private function sanitizeFilenameForLog(?string $name): ?string
    {
        if (!is_string($name) || $name === '') {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? $name;
        return substr($clean, 0, 200);
    }

    /**
     * Asegura que el archivo no haya cambiado durante el procesamiento.
     *
     * Compara el hash del archivo antes y después del procesamiento
     * para detectar modificaciones.
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
            throw new UploadValidationException('Unable to resolve upload path for integrity check.');
        }

        $current = hash_file('sha256', $path);
        if ($current === false || $current !== $expectedHash) {
            throw new UploadValidationException('Upload content changed during processing.');
        }
    }
}
