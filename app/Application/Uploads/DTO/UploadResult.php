<?php // DTO para resultados de upload

declare(strict_types=1); // Tipado estricto

namespace App\Application\Uploads\DTO; // Namespace de DTOs de uploads

/**
 * Representa un upload exitoso.
 */
final class UploadResult // DTO inmutable
{
    /**
     * @param string $id Identificador del upload (uuid o media id)
     * @param int|string $tenantId Tenant propietario
     * @param string $profileId Perfil usado (ej. avatar_image)
     * @param string $disk Disco de almacenamiento
     * @param string $path Path relativo en el disco
     * @param string $mime MIME real del archivo
     * @param int $size Tamaño en bytes
     * @param string|null $checksum Checksum opcional
     * @param string $status Estado del upload (ej. stored/attached)
     * @param string|null $correlationId ID para correlación (quarantine/scan)
     */
    public function __construct(
        public readonly string $id, // Identificador del upload (uuid o media id)
        public readonly int|string $tenantId, // Tenant propietario
        public readonly string $profileId, // ID del perfil de upload
        public readonly string $disk, // Disco donde se almacenó
        public readonly string $path, // Path relativo
        public readonly string $mime, // MIME detectado
        public readonly int $size, // Tamaño en bytes
        public readonly ?string $checksum, // Checksum opcional
        public readonly string $status, // Estado final
        public readonly ?string $correlationId = null, // ID de correlación (quarantine)
    ) {
    }
}
