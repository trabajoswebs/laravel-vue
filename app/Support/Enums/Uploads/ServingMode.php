<?php // Enum que define cómo se sirve un upload

declare(strict_types=1); // Tipado estricto

namespace App\Support\Enums\Uploads; // Namespace de enums de uploads

/**
 * Modos de entrega de archivos subidos.
 */
enum ServingMode: string // Enum de modos de serving
{
    case CONTROLLER_SIGNED = 'controller_signed'; // Se sirve vía controlador con URL firmada
    case PRIVATE_SIGNED = 'private_signed'; // Se sirve desde storage con URL temporal privada
    case FORBIDDEN = 'forbidden'; // No se permite servir el archivo
    case PUBLIC = 'public';
}
