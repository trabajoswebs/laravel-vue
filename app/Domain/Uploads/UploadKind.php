<?php // Enum que clasifica el tipo de upload

declare(strict_types=1); // Tipado estricto

namespace App\Domain\Uploads; // Namespace de uploads de dominio

/**
 * Tipos de archivo soportados por perfiles de upload.
 */
enum UploadKind: string // Enum con categorías de upload
{
    case IMAGE = 'image'; // Imágenes (avatar, galería)
    case DOCUMENT = 'document'; // Documentos PDF
    case SPREADSHEET = 'spreadsheet'; // Hojas de cálculo (xlsx)
    case IMPORT = 'import'; // Archivos de importación (csv)
    case SECRET = 'secret'; // Archivos sensibles (certificados)
}
