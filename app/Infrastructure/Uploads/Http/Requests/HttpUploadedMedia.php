<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Requests;

use App\Application\Uploads\Media\Contracts\UploadedMedia;
use Illuminate\Http\UploadedFile;

/**
 * Adaptador que envuelve un Illuminate UploadedFile como UploadedMedia de aplicación.
 * 
 * Convierte un archivo subido de Laravel en una representación agnóstica
 * de infraestructura para su uso en la capa de aplicación.
 */
final class HttpUploadedMedia implements UploadedMedia
{
    public function __construct(private readonly UploadedFile $file) {}

    /**
     * Obtiene el nombre original del archivo subido.
     *
     * @return string Nombre original del archivo
     */
    public function originalName(): string
    {
        return (string) $this->file->getClientOriginalName();
    }

    /**
     * Obtiene el tipo MIME del archivo subido.
     *
     * @return string|null Tipo MIME del archivo o null si no está disponible
     */
    public function mimeType(): ?string
    {
        return $this->file->getMimeType();
    }

    /**
     * Obtiene el tamaño del archivo en bytes.
     *
     * @return int|null Tamaño del archivo en bytes o null si no está disponible
     */
    public function size(): ?int
    {
        $size = $this->file->getSize();

        return is_int($size) ? $size : null;
    }

    /**
     * Obtiene la instancia original del archivo subido.
     *
     * @return mixed Instancia de UploadedFile de Laravel
     */
    public function raw(): mixed
    {
        return $this->file;
    }
}
