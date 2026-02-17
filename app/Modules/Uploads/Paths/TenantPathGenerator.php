<?php // Generador de paths tenant-first para uploads

declare(strict_types=1); // Tipado estricto

namespace App\Modules\Uploads\Paths; // Namespace de infraestructura de uploads

use App\Support\Contracts\TenantContextInterface; // Contexto de tenant
use App\Domain\Uploads\UploadProfile; // Perfil de upload

/**
 * Genera rutas finales para archivos subidos respetando el tenant.
 */
class TenantPathGenerator // Servicio para construir paths
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext, // Injerta contexto de tenant
        private readonly TenantPathLayout $layout, // Layout común tenant-first
    )
    {
    }

    /**
     * Genera el path final para un archivo según el perfil.
     *
     * @param UploadProfile $profile Perfil de subida
     * @param int|string|null $ownerId Identificador del owner (opcional)
     * @param string $extension Extensión normalizada (ej. jpg)
     * @param int|null $version Versión para avatars
     * @param string|null $uniqueId Identificador único opcional (UUID)
     * @return string Path relativo en el disco
     */
    public function generate(UploadProfile $profile, int|string|null $ownerId, string $extension, ?int $version = null, ?string $uniqueId = null): string // Devuelve path tenant-first
    {
        $tenantId = $this->tenantContext->requireTenantId(); // Obtiene tenant_id activo o lanza
        return $this->generateForTenant($profile, $tenantId, $ownerId, $extension, $version, $uniqueId);
    }

    /**
     * Genera el path final usando un tenantId explícito.
     */
    public function generateForTenant(
        UploadProfile $profile,
        int|string $tenantId,
        int|string|null $ownerId,
        string $extension,
        ?int $version = null,
        ?string $uniqueId = null
    ): string {
        $date = now(); // Fecha actual para carpetas año/mes

        return $this->layout->pathForProfile($profile, $tenantId, $ownerId, $extension, $version, $uniqueId, $date); // Delegación al layout común
    }
}
