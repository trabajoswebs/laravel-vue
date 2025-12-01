<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;


use App\Domain\Media\Contracts\MediaResource;
use App\Application\Media\Contracts\MediaOwner;
use App\Application\Media\Contracts\MediaProfile;
use App\Application\Media\Contracts\UploadedMedia;

/**
 * Puerto de aplicación para subir y adjuntar imágenes.
 * 
 * Define la interfaz para la operación de subida de archivos multimedia
 * que permite adjuntar un archivo a un propietario de medios.
 */
interface MediaUploader
{
    /**
     * Encola una subida para procesamiento asíncrono.
     *
     * @param MediaOwner $owner Propietario del media (por ejemplo, un usuario)
     * @param UploadedMedia $file Archivo subido a procesar
     * @param MediaProfile $profile Perfil de configuración para el media
     * @param string|null $correlationId Identificador de correlación opcional para trazabilidad
     * @return \App\Application\Media\DTO\QueuedUploadResult Ticket de procesamiento en segundo plano
     */
    public function upload(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): \App\Application\Media\DTO\QueuedUploadResult;

    /**
     * Sube y adjunta un archivo multimedia de forma síncrona (compatibilidad).
     *
     * @param MediaOwner $owner Propietario del media (por ejemplo, un usuario)
     * @param UploadedMedia $file Archivo subido a procesar
     * @param MediaProfile $profile Perfil de configuración para el media
     * @param string|null $correlationId Identificador de correlación opcional para trazabilidad
     * @return MediaResource Recurso multimedia creado
     */
    public function uploadSync(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): MediaResource;
}
