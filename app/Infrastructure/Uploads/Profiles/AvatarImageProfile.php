<?php // Perfil de subida para avatar de usuario

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Profiles; // Namespace de perfiles de uploads infra

use App\Support\Enums\Uploads\ProcessingMode; // Enum de procesamiento
use App\Support\Enums\Uploads\ScanMode; // Enum de escaneo
use App\Support\Enums\Uploads\ServingMode; // Enum de serving
use App\Support\Enums\Uploads\UploadKind; // Enum de tipo de upload
use App\Domain\Uploads\UploadProfile; // Perfil base
use App\Domain\Uploads\UploadProfileId; // VO de identificador

/**
 * Perfil específico para subir avatares.
 */
final class AvatarImageProfile extends UploadProfile // Define configuración del perfil avatar
{
    public function __construct()
    {
        parent::__construct(
            id: new UploadProfileId('avatar_image'), // ID único del perfil avatar
            kind: UploadKind::IMAGE, // Se trata de una imagen
            allowedMimes: ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], // MIMEs permitidos
            maxBytes: (int) config('image-pipeline.max_bytes', 25 * 1024 * 1024), // Límite real de imagen
            scanMode: ScanMode::REQUIRED, // Escaneo obligatorio
            processingMode: ProcessingMode::IMAGE_PIPELINE, // Usa pipeline de imágenes
            servingMode: ServingMode::CONTROLLER_SIGNED, // Se sirve vía controlador con firma
            disk: config('image-pipeline.avatar_disk', config('filesystems.default', 'public')), // Disco configurado
            pathCategory: 'avatars', // Categoría de path para avatars
            requiresOwner: true, // Requiere owner para path tenant-first
        );
    }
}
