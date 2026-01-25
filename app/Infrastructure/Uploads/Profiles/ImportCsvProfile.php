<?php // Perfil de subida para archivos CSV de importación

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Profiles; // Namespace de perfiles de uploads infra

use App\Domain\Uploads\ProcessingMode; // Enum de procesamiento
use App\Domain\Uploads\ScanMode; // Enum de escaneo
use App\Domain\Uploads\ServingMode; // Enum de serving
use App\Domain\Uploads\UploadKind; // Enum de tipo de upload
use App\Domain\Uploads\UploadProfile; // Perfil base
use App\Domain\Uploads\UploadProfileId; // VO de identificador

/**
 * Perfil para archivos CSV de importación.
 */
final class ImportCsvProfile extends UploadProfile // Configuración para CSV de import
{
    public function __construct()
    {
        parent::__construct(
            id: new UploadProfileId('import_csv'), // ID de perfil CSV import
            kind: UploadKind::IMPORT, // Tipo importación
            allowedMimes: ['text/csv', 'text/plain'], // MIMEs comunes para CSV
            maxBytes: 10 * 1024 * 1024, // Límite 10MB
            scanMode: ScanMode::REQUIRED, // Escaneo obligatorio
            processingMode: ProcessingMode::NONE, // Sin procesamiento
            servingMode: ServingMode::FORBIDDEN, // No se sirve públicamente
            disk: config('filesystems.default', 'public'), // Disco por defecto
            pathCategory: 'imports', // Categoría de path
            requiresOwner: false, // Owner opcional
        );
    }
}
