<?php

declare(strict_types=1);

namespace App\Application\Uploads\Contracts;

use App\Application\Uploads\DTO\UploadResult;
use App\Domain\Uploads\UploadProfile;
use App\Models\User;
use App\Infrastructure\Uploads\Core\Contracts\UploadedMedia;

/**
 * Contrato del orquestador de uploads.
 * 
 * Define la interfaz para los orquestadores responsables de manejar
 * las operaciones de subida de archivos, incluyendo validación,
 * procesamiento y persistencia.
 * 
 * @package App\Application\Uploads\Contracts
 */
interface UploadOrchestratorInterface
{
    /**
     * Sube un archivo según el perfil (creación).
     * 
     * Realiza la subida de un archivo nuevo según las reglas definidas
     * en el perfil especificado, incluyendo validación, procesamiento
     * y persistencia del archivo.
     *
     * @param UploadProfile $profile Perfil de upload que define las reglas de validación y procesamiento
     * @param User $actor Usuario que realiza la operación de subida
     * @param UploadedMedia $file Archivo subido envuelto en una interfaz común
     * @param int|string|null $ownerId ID del propietario del archivo (opcional)
     * @param string|null $correlationId ID de correlación para rastreo de solicitudes (opcional)
     * @param array<string, mixed> $meta Metadatos adicionales para el upload
     * @return UploadResult Resultado de la operación de subida con información detallada
     */
    public function upload(
        UploadProfile $profile,
        User $actor,
        UploadedMedia $file,
        int|string|null $ownerId = null,
        ?string $correlationId = null,
        array $meta = [],
    ): UploadResult;
}
