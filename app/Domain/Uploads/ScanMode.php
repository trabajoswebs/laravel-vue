<?php // Enum que define el modo de escaneo AV

declare(strict_types=1); // Tipado estricto

namespace App\Domain\Uploads; // Namespace de uploads de dominio

/**
 * Modo de escaneo antivirus para un perfil.
 */
enum ScanMode: string // Enum de modos de escaneo
{
    case REQUIRED = 'required'; // Escaneo obligatorio
    case OPTIONAL = 'optional'; // Escaneo opcional
    case DISABLED = 'disabled'; // Escaneo deshabilitado
}
