<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Image;

use App\Support\Logging\SecurityLogger;
/**
 * Value Object que representa el resultado del procesamiento de una imagen.
 *
 * Almacena información sobre el archivo procesado (ruta, dimensiones, MIME, etc.)
 * y se encarga de eliminar automáticamente el archivo temporal asociado
 * cuando la instancia es destruida o cuando se llama explícitamente al método `cleanup()`.
 *
 * @example
 * $result = $pipeline->process($file);
 * echo $result->width; // 800
 * $result->cleanup(); // Elimina manualmente el archivo temporal
 */
final class ImagePipelineResult
{
    /**
     * Indica si el archivo temporal ya ha sido limpiado.
     */
    private bool $cleaned = false;

    /**
     * Constructor del Value Object.
     *
     * @param string $path Ruta del archivo temporal procesado.
     * @param string $mime Tipo MIME del archivo (e.g., image/jpeg).
     * @param string $extension Extensión del archivo (e.g., jpg).
     * @param int $width Ancho de la imagen en píxeles.
     * @param int $height Alto de la imagen en píxeles.
     * @param int $bytes Tamaño del archivo en bytes.
     * @param string $contentHash Hash SHA1 del contenido del archivo.
     */
    public function __construct(
        private string $path,        // Ruta del archivo temporal procesado
        private string $mime,        // Tipo MIME del archivo (e.g., image/jpeg)
        private string $extension,   // Extensión del archivo (e.g., jpg)
        private int $width,          // Ancho de la imagen en píxeles
        private int $height,         // Alto de la imagen en píxeles
        private int $bytes,          // Tamaño del archivo en bytes
        private string $contentHash, // Hash SHA1 del contenido del archivo
    ) {}

    /**
     * Obtiene la ruta del archivo temporal procesado.
     *
     * @return string Ruta del archivo.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Obtiene el tipo MIME del archivo procesado.
     *
     * @return string Tipo MIME (e.g., image/jpeg).
     */
    public function mime(): string
    {
        return $this->mime;
    }

    /**
     * Obtiene la extensión del archivo procesado.
     *
     * @return string Extensión (e.g., jpg).
     */
    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Obtiene el ancho de la imagen en píxeles.
     *
     * @return int Ancho de la imagen.
     */
    public function width(): int
    {
        return $this->width;
    }

    /**
     * Obtiene el alto de la imagen en píxeles.
     *
     * @return int Alto de la imagen.
     */
    public function height(): int
    {
        return $this->height;
    }

    /**
     * Obtiene el tamaño del archivo en bytes.
     *
     * @return int Tamaño del archivo.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Obtiene el hash SHA1 del contenido del archivo.
     *
     * @return string Hash SHA1 del contenido.
     */
    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * Indica si el archivo temporal ya fue limpiado.
     *
     * @return bool `true` si el archivo fue limpiado, `false` en caso contrario.
     */
    public function isCleaned(): bool
    {
        return $this->cleaned;
    }

    /**
     * Transfiere la responsabilidad de cleanup cuando el archivo fue movido
     * a su destino final por otro componente.
     */
    public function releaseCleanupOwnership(): void
    {
        $this->cleaned = true;
    }

    /**
     * Elimina manualmente el archivo temporal asociado a este resultado.
     *
     * Si el archivo ya fue limpiado previamente o no se encuentra en un directorio
     * temporal seguro, la operación se omite. Si falla al eliminarlo, registra un mensaje de aviso.
     *
     * @return void
     */
    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        if (!$this->isPathDeletable()) {
            SecurityLogger::warning('image_pipeline_cleanup_skipped', [
                'path' => $this->path,
            ]);

            return;
        }

        if (!@unlink($this->path)) {
            SecurityLogger::warning('image_pipeline_cleanup_failed', [
                'path' => basename($this->path), // Registra solo el nombre del archivo por seguridad
            ]);
        }
    }

    /**
     * Destructor: intenta limpiar el archivo temporal si sigue existiendo.
     *
     * Este método se llama automáticamente cuando la instancia deja de tener referencias.
     * Registra un mensaje de depuración si ocurre una excepción durante la limpieza.
     *
     * @return void
     */
    public function __destruct()
    {
        try {
            $this->cleanup();
        } catch (\Throwable $exception) {
            SecurityLogger::debug('image_pipeline_cleanup_exception', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Verifica si la ruta del archivo es segura para ser eliminada.
     *
     * Comprueba que la ruta sea un archivo regular, esté dentro del directorio
     * temporal del sistema y sea escribible. Esto previene la eliminación accidental
     * de archivos importantes fuera del directorio temporal.
     *
     * @return bool `true` si la ruta es segura para eliminar, `false` en caso contrario.
     */
    private function isPathDeletable(): bool
    {
        if (!\is_string($this->path) || $this->path === '') {
            return false;
        }

        $real = @\realpath($this->path);
        if ($real === false) {
            return false;
        }

        $tmpDir = \rtrim(\sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (\str_starts_with($real, $tmpDir) && \is_file($real) && \is_writable($real)) {
            return true;
        }

        return false;
    }
}
