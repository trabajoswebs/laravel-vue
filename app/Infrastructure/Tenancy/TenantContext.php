<?php // Implementación de TenantContextInterface para resolver tenant actual

declare(strict_types=1); // Activa tipado estricto

namespace App\Infrastructure\Tenancy; // Namespace de infraestructura de tenancy

use App\Support\Contracts\TenantContextInterface; // Contrato compartido de tenant
use App\Models\Tenant; // Modelo Tenant propio
use Illuminate\Support\Facades\Auth; // Facade de autenticación
use RuntimeException; // Excepción para requerir tenant

/**
 * Provee el contexto del tenant actual a la capa de aplicación.
 */
class TenantContext implements TenantContextInterface // Implementa el contrato para exponer tenant_id
{
    /**
     * Obtiene el ID del tenant actual o null si no existe.
     *
     * @return int|string|null ID de tenant actual
     */
    public function tenantId(): int|string|null // Devuelve el tenant_id actual si está disponible
    {
        $current = function_exists('tenant') ? tenant() : null; // Usa helper tenant() si está disponible

        if ($current instanceof Tenant) { // Si el helper tiene un tenant válido
            return $current->getKey(); // Devuelve la PK del tenant
        }

        return Auth::user()?->getCurrentTenantId(); // Fallback: lee el tenant_id del usuario autenticado
    }

    /**
     * Obtiene el ID del tenant activo y lanza excepción si no está definido.
     *
     * @return int|string ID de tenant activo
     */
    public function requireTenantId(): int|string // Requiere que exista un tenant resuelto
    {
        $tenantId = $this->tenantId(); // Reutiliza tenantId para resolver el valor

        if ($tenantId === null) { // Si no se pudo resolver tenant
            throw new RuntimeException('No hay un tenant activo para la solicitud'); // Excepción explícita para flujos protegidos
        }

        return $tenantId; // Retorna el tenant_id garantizado
    }
}
