<?php

declare(strict_types=1);

namespace App\Domain\Media\Contracts;

/**
 * Representa un media persistido sin exponer detalles de infraestructura.
 */
interface MediaResource
{
    /**
     * Identificador único del media.
     *
     * @return string|int ID único del recurso multimedia
     */
    public function getKey(): string|int;

    /**
     * Obtiene el nombre de la colección del media.
     *
     * @return string|null Nombre de la colección o null si no está definido
     */
    public function collectionName(): ?string;

    /**
     * Obtiene el nombre del disco de almacenamiento.
     *
     * @return string|null Nombre del disco o null si no está definido
     */
    public function disk(): ?string;

    /**
     * Obtiene el nombre del archivo.
     *
     * @return string|null Nombre del archivo o null si no está definido
     */
    public function fileName(): ?string;

    /**
     * Obtiene la URL pública del media.
     *
     * @return string|null URL del media o null si no está disponible
     */
    public function url(): ?string;

    /**
     * Acceso al objeto subyacente (infraestructura).
     *
     * @return mixed Instancia original del recurso multimedia
     */
    public function raw(): mixed;
}
