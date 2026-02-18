<?php // Perfil de subida para imágenes de galería

declare(strict_types=1); // Tipado estricto

namespace App\Modules\Uploads\Profiles; // Namespace de perfiles de uploads infra

use App\Support\Enums\Uploads\ProcessingMode; // Enum de procesamiento
use App\Support\Enums\Uploads\ScanMode; // Enum de escaneo
use App\Support\Enums\Uploads\ServingMode; // Enum de serving
use App\Support\Enums\Uploads\UploadKind; // Enum de tipo de upload
use App\Domain\Uploads\UploadProfile; // Perfil base
use App\Domain\Uploads\UploadProfileId; // VO de identificador

/**
 * Perfil para imágenes de galería.
 */
final class GalleryImageProfile extends UploadProfile // Define configuración para galería
{
    public function __construct()
    {
        parent::__construct(
            id: new UploadProfileId('gallery_image'), // ID de perfil galería
            kind: UploadKind::IMAGE, // Es una imagen
            allowedMimes: ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], // MIMEs permitidos
            maxBytes: (int) config('image-pipeline.max_bytes', 25 * 1024 * 1024), // Límite real de bytes
            scanMode: ScanMode::REQUIRED, // Escaneo obligatorio
            processingMode: ProcessingMode::IMAGE_PIPELINE, // Usa pipeline de imágenes
            servingMode: ServingMode::CONTROLLER_SIGNED, // Se sirve vía controlador
            disk: config('filesystems.default', 'public'), // Disco por defecto
            pathCategory: 'images', // Categoría de path para imágenes
            requiresOwner: false, // Owner opcional (no se usa en path genérico)
        );
    }
}
