<?php // Perfil de subida para documentos PDF

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Profiles; // Namespace de perfiles de uploads infra

use App\Support\Enums\Uploads\ProcessingMode; // Enum de procesamiento
use App\Support\Enums\Uploads\ScanMode; // Enum de escaneo
use App\Support\Enums\Uploads\ServingMode; // Enum de serving
use App\Support\Enums\Uploads\UploadKind; // Enum de tipo de upload
use App\Domain\Uploads\UploadProfile; // Perfil base
use App\Domain\Uploads\UploadProfileId; // VO de identificador

/**
 * Perfil para documentos PDF.
 */
final class DocumentPdfProfile extends UploadProfile // Configuración para PDFs
{
    public function __construct()
    {
        parent::__construct(
            id: new UploadProfileId('document_pdf'), // ID de perfil PDF
            kind: UploadKind::DOCUMENT, // Tipo documento
            allowedMimes: ['application/pdf'], // Solo PDF
            maxBytes: 15 * 1024 * 1024, // Límite 15MB por defecto
            scanMode: ScanMode::REQUIRED, // Escaneo obligatorio
            processingMode: ProcessingMode::NONE, // Sin procesamiento adicional
            servingMode: ServingMode::CONTROLLER_SIGNED, // Se sirve vía controlador con firma
            disk: (string) config('uploads.private_disk', config('filesystems.default', 'public')), // Disco privado de SSOT
            pathCategory: 'documents', // Categoría de path para documentos
            requiresOwner: false, // Owner opcional
        );
    }
}
