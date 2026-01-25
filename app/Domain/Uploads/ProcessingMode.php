<?php // Enum que define el modo de procesamiento posterior al upload

declare(strict_types=1); // Tipado estricto

namespace App\Domain\Uploads; // Namespace de uploads de dominio

/**
 * Modo de procesamiento para archivos subidos.
 */
enum ProcessingMode: string // Enum de modos de procesamiento
{
    case IMAGE_PIPELINE = 'image_pipeline'; // Procesamiento de imágenes (resize/normalize)
    case NONE = 'none'; // Sin procesamiento adicional
}
