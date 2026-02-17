<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Adapters;

use App\Modules\Uploads\Contracts\MediaResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adaptador de Media de Spatie hacia el contrato de dominio.
 */
final class SpatieMediaResource implements MediaResource
{
    public function __construct(private readonly Media $media) {}

    /**
     * Obtiene la clave primaria del media.
     *
     * @return string|int ID del media
     */
    public function getKey(): string|int
    {
        return $this->media->getKey();
    }

    /**
     * Obtiene el nombre de la colección del media.
     *
     * @return string|null Nombre de la colección o null si no está definido
     */
    public function collectionName(): ?string
    {
        return is_string($this->media->collection_name ?? null) ? $this->media->collection_name : null;
    }

    /**
     * Obtiene el nombre del disco de almacenamiento.
     *
     * @return string|null Nombre del disco o null si no está definido
     */
    public function disk(): ?string
    {
        return is_string($this->media->disk ?? null) ? $this->media->disk : null;
    }

    /**
     * Obtiene el nombre del archivo.
     *
     * @return string|null Nombre del archivo o null si no está definido
     */
    public function fileName(): ?string
    {
        return is_string($this->media->file_name ?? null) ? $this->media->file_name : null;
    }

    /**
     * Obtiene la URL pública del media.
     *
     * @return string|null URL del media o null si no está disponible
     */
    public function url(): ?string
    {
        try {
            $url = $this->media->getUrl();

            return is_string($url) ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Obtiene la instancia original de Spatie Media.
     *
     * @return mixed Instancia original del modelo Media
     */
    public function raw(): mixed
    {
        return $this->media;
    }
}
