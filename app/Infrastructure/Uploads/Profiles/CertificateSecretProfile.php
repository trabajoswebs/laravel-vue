<?php // Perfil de subida para certificados/secretos

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Profiles; // Namespace de perfiles de uploads infra

use App\Support\Enums\Uploads\ProcessingMode; // Enum de procesamiento
use App\Support\Enums\Uploads\ScanMode; // Enum de escaneo
use App\Support\Enums\Uploads\ServingMode; // Enum de serving
use App\Support\Enums\Uploads\UploadKind; // Enum de tipo de upload
use App\Domain\Uploads\UploadProfile; // Perfil base
use App\Domain\Uploads\UploadProfileId; // VO de identificador

/**
 * Perfil para certificados digitales u otros secretos.
 */
final class CertificateSecretProfile extends UploadProfile // Configuración para certificados
{
    public function __construct()
    {
        parent::__construct(
            id: new UploadProfileId('certificate_secret'), // ID de perfil certificado
            kind: UploadKind::SECRET, // Tipo secreto
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'], // MIMEs comunes para .p12
            maxBytes: 5 * 1024 * 1024, // Límite 5MB
            scanMode: ScanMode::REQUIRED, // Escaneo obligatorio
            processingMode: ProcessingMode::NONE, // Sin procesamiento adicional
            servingMode: ServingMode::FORBIDDEN, // No se sirve públicamente
            disk: config('filesystems.default', 'private'), // Disco privado sugerido
            pathCategory: 'secrets', // Categoría de path para secretos
            requiresOwner: false, // Owner opcional
        );
    }
}
