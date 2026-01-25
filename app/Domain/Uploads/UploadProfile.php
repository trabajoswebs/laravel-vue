<?php // Perfil de subida que define política y seguridad

declare(strict_types=1); // Tipado estricto

namespace App\Domain\Uploads; // Namespace de uploads de dominio

/**
 * Describe un perfil de subida (avatar, PDF, CSV, etc.).
 */
class UploadProfile // Perfil con metadatos de seguridad y serving
{
    /**
     * @param UploadProfileId $id Identificador único (ej. avatar_image)
     * @param UploadKind $kind Tipo de archivo (IMAGE, DOCUMENT, etc.)
     * @param array<string> $allowedMimes Lista de MIME permitidos
     * @param int $maxBytes Límite máximo de bytes aceptados
     * @param ScanMode $scanMode Modo de escaneo AV
     * @param ProcessingMode $processingMode Modo de procesamiento posterior
     * @param ServingMode $servingMode Cómo se servirá el archivo
     * @param string $disk Nombre del disco de storage sugerido
     * @param string $pathCategory Categoría para generar paths (avatars, images, documents, etc.)
     * @param bool $requiresOwner Si requiere owner explícito para generar path
     */
    public function __construct(
        public readonly UploadProfileId $id, // ID único del perfil (ej. avatar_image)
        public readonly UploadKind $kind, // Tipo de archivo (image/document/etc.)
        public readonly array $allowedMimes, // MIMEs permitidos para el perfil
        public readonly int $maxBytes, // Límite de tamaño en bytes
        public readonly ScanMode $scanMode, // Política de escaneo antivirus
        public readonly ProcessingMode $processingMode, // Modo de procesamiento posterior
        public readonly ServingMode $servingMode, // Cómo se entregará el archivo
        public readonly string $disk, // Disco de storage sugerido
        public readonly string $pathCategory, // Categoría usada por el generador de paths
        public readonly bool $requiresOwner = false, // Indica si necesita owner para el path (ej. avatar)
    ) {
    }
}
