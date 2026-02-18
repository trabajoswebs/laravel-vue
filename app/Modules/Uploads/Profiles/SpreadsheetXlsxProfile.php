<?php // Perfil de subida para hojas de cálculo XLSX

declare(strict_types=1); // Tipado estricto

namespace App\Modules\Uploads\Profiles; // Namespace de perfiles de uploads infra

use App\Support\Enums\Uploads\ProcessingMode; // Enum de procesamiento
use App\Support\Enums\Uploads\ScanMode; // Enum de escaneo
use App\Support\Enums\Uploads\ServingMode; // Enum de serving
use App\Support\Enums\Uploads\UploadKind; // Enum de tipo de upload
use App\Domain\Uploads\UploadProfile; // Perfil base
use App\Domain\Uploads\UploadProfileId; // VO de identificador

/**
 * Perfil para hojas de cálculo XLSX.
 */
final class SpreadsheetXlsxProfile extends UploadProfile // Configuración para XLSX
{
    public function __construct()
    {
        parent::__construct(
            id: new UploadProfileId('spreadsheet_xlsx'), // ID de perfil XLSX
            kind: UploadKind::SPREADSHEET, // Tipo spreadsheet
            allowedMimes: ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], // MIME válidos para XLSX (ZIP)
            maxBytes: 15 * 1024 * 1024, // Límite 15MB
            scanMode: ScanMode::REQUIRED, // Escaneo obligatorio
            processingMode: ProcessingMode::NONE, // Sin procesamiento adicional
            servingMode: ServingMode::CONTROLLER_SIGNED, // Se sirve vía controlador con firma
            disk: config('filesystems.default', 'public'), // Disco por defecto
            pathCategory: 'spreadsheets', // Categoría para paths de hojas de cálculo
            requiresOwner: false, // Owner opcional
        );
    }
}
