<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

/**
 * Representa un archivo subido de forma agnóstica a infraestructura.
 *
 * Implementaciones en infraestructura pueden envolver `Illuminate\Http\UploadedFile`
 * u otros orígenes, pero desde Application solo se necesitan metadatos mínimos.
 */
interface UploadedMedia
{
    /**
     * Nombre original reportado por el cliente.
     *
     * @return string Nombre original del archivo
     */
    public function originalName(): string;

    /**
     * MIME type declarado o detectado (si está disponible).
     *
     * @return string|null Tipo MIME del archivo o null si no está disponible
     */
    public function mimeType(): ?string;

    /**
     * Tamaño del archivo en bytes (si está disponible).
     *
     * @return int|null Tamaño del archivo en bytes o null si no está disponible
     */
    public function size(): ?int;

    /**
     * Acceso al objeto subyacente (solo para adaptadores de infraestructura).
     *
     * @return mixed Instancia original del archivo subido
     */
    public function raw(): mixed;
}
