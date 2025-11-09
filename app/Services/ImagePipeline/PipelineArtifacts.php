<?php

declare(strict_types=1);

namespace App\Services\ImagePipeline;

use RuntimeException;

/**
 * Gestiona operaciones comunes sobre archivos temporales dentro del pipeline.
 * 
 * Esta clase se encarga de crear rutas de archivos temporales únicos,
 * validar su tamaño, calcular hashes de contenido, determinar el MIME
 * a partir de la extensión y eliminar archivos de forma segura.
 * 
 * @example
 * $artifacts = new PipelineArtifacts($logger);
 * $tempPath = $artifacts->tempFilePath('jpg');
 * $size = $artifacts->ensureTempFileValid($tempPath);
 * $hash = $artifacts->computeContentHash($tempPath);
 * $mime = $artifacts->mimeFromExtension('png');
 * $artifacts->safeUnlink($tempPath);
 */
final class PipelineArtifacts
{
    public function __construct(
        private readonly PipelineLogger $logger,
    ) {}

    /**
     * Genera una ruta única para un archivo temporal con la extensión dada.
     * 
     * @param string $extension La extensión del archivo (e.g., 'jpg', 'png').
     * @return string La ruta completa del archivo temporal (e.g., '/tmp/img_norm_a1b2c3.jpg').
     */
    public function tempFilePath(string $extension): string
    {
        $base = \rtrim(\sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $name = 'img_norm_' . \bin2hex(\random_bytes(6)) . '.' . \ltrim($extension, '.');

        return $base . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Valida que un archivo temporal exista y tenga un tamaño válido.
     * 
     * Si el archivo no existe o su tamaño es inválido, lo elimina y lanza una excepción.
     * 
     * @param string $tempPath Ruta del archivo temporal.
     * @return int El tamaño del archivo en bytes.
     * @throws RuntimeException Si el archivo no es válido.
     */
    public function ensureTempFileValid(string $tempPath): int
    {
        $bytes = \filesize($tempPath);

        if ($bytes === false || $bytes <= 0) {
            $this->safeUnlink($tempPath);
            throw new RuntimeException(__('image-pipeline.temp_file_invalid'));
        }

        return (int) $bytes;
    }

    /**
     * Calcula un hash SHA1 del contenido del archivo.
     * 
     * Si falla, genera un hash aleatorio como respaldo.
     * 
     * @param string $tempPath Ruta del archivo.
     * @return string El hash SHA1 del contenido o un hash aleatorio si falla.
     */
    public function computeContentHash(string $tempPath): string
    {
        $attempts = 0;
        while ($attempts < 2) {
            $hash = @\hash_file('sha1', $tempPath);
            if (\is_string($hash) && $hash !== '') {
                return $hash;
            }

            \clearstatcache(true, $tempPath);
            $attempts++;
        }

        $this->logger->log('error', 'image_pipeline_hash_failed', [
            'path' => \basename($tempPath),
        ]);

        throw new RuntimeException(__('image-pipeline.content_hash_failed'));
    }

    /**
     * Devuelve el tipo MIME correspondiente a una extensión de archivo.
     * 
     * @param string $extension Extensión del archivo (e.g., 'jpg', 'png').
     * @return string Tipo MIME correspondiente (e.g., 'image/jpeg').
     */
    public function mimeFromExtension(string $extension): string
    {
        return match (\strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'application/octet-stream',
        };
    }

    /**
     * Elimina un archivo de forma segura.
     * 
     * Si la operación falla, registra un mensaje de advertencia.
     * 
     * @param string $path Ruta del archivo a eliminar.
     */
    public function safeUnlink(string $path): void
    {
        if (@\unlink($path)) {
            return;
        }

        $this->logger->log('warning', 'image_pipeline_tmp_unlink_failed', [
            'path' => \basename($path), // Registra solo el nombre del archivo por seguridad
        ]);
    }
}
