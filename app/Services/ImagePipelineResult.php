<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Value Object del resultado del pipeline con cleanup automático del temporal.
 * 
 * Representa el resultado del procesamiento de una imagen. Almacena información
 * como la ruta del archivo, dimensiones, tipo MIME, etc. Se encarga de eliminar
 * automáticamente el archivo temporal cuando la instancia es destruida.
 * 
 * @example
 * $result = $pipeline->process($file);
 * echo $result->width; // 800
 * $result->cleanup(); // Elimina manualmente el archivo temporal
 */
final class ImagePipelineResult
{
    private bool $cleaned = false;

    public function __construct(
        private string $path,        // Ruta del archivo temporal procesado
        private string $mime,        // Tipo MIME del archivo (e.g., image/jpeg)
        private string $extension,   // Extensión del archivo (e.g., jpg)
        private int $width,          // Ancho de la imagen en píxeles
        private int $height,         // Alto de la imagen en píxeles
        private int $bytes,          // Tamaño del archivo en bytes
        private string $contentHash, // Hash SHA1 del contenido del archivo
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * Elimina manualmente el archivo temporal asociado a este resultado.
     * 
     * Si la operación falla, registra un mensaje de aviso.
     */
    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        if (!$this->isPathDeletable()) {
            Log::warning('image_pipeline_cleanup_skipped', [
                'path' => $this->path,
            ]);

            return;
        }

        if (!@unlink($this->path)) {
            Log::notice('image_pipeline_cleanup_failed', [
                'path' => basename($this->path), // Registra solo el nombre del archivo por seguridad
            ]);
        }
    }

    /**
     * Destructor: intenta limpiar el archivo temporal si sigue existiendo.
     */
    public function __destruct()
    {
        try {
            $this->cleanup();
        } catch (\Throwable $exception) {
            Log::debug('image_pipeline_cleanup_exception', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

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
