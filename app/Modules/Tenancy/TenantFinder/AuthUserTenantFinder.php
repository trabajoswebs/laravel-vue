<?php // TenantFinder (Spatie) que resuelve por usuario autenticado

declare(strict_types=1); // Habilita tipado estricto

namespace App\Modules\Tenancy\TenantFinder; // Namespace para finders de tenant

use App\Models\Tenant; // Modelo Tenant propio
use Illuminate\Http\Request; // Request HTTP

use Spatie\Multitenancy\Contracts\IsTenant; // Contrato esperado por Spatie // Ej.: return ?IsTenant
use Spatie\Multitenancy\TenantFinder\TenantFinder; // Base class requerida // Ej.: extends TenantFinder



/**
 * Resuelve el tenant actual a partir del usuario autenticado.
 */
final class AuthUserTenantFinder extends TenantFinder // Finder válido para Spatie // Ej.: auto-determina tenant al inicio del request
{
    /**
     * Obtiene el tenant aplicable para la request.
     *
     * @param Request $request Request HTTP entrante
     * @return IsTenant|null Tenant encontrado o null si no corresponde
     */
    public function findForRequest(Request $request): ?IsTenant // Firma exigida por Spatie // Ej.: ?IsTenant
    {
        $user = $request->user(); // Obtiene el usuario autenticado del request // Ej.: User #2

        if ($user === null) { // Si no hay usuario autenticado
            return null; // No se puede resolver tenant
        }

        $tenantId = $user->current_tenant_id // Campo directo; ej. 1
            ?? (method_exists($user, 'getCurrentTenantId') ? $user->getCurrentTenantId() : null); // Fallback; ej. 1

        if ($tenantId === null) { // Si no hay tenant asignado
            return null; // No se resuelve tenant
        }

        $tenant = Tenant::query()->find($tenantId); // Busca el tenant por su PK

        if (! $tenant) { // Si el tenant no existe
            return null; // Evita resolver a un tenant inexistente
        }

        $belongs = $tenant->users()->whereKey($user->getKey())->exists(); // Verifica pertenencia en tenant_user

        if (! $belongs) { // Si el usuario no pertenece al tenant referenciado
            return null; // No resuelve tenant para evitar bypass de sesión manipulada
        }

        return $tenant; // Devuelve tenant válido y verificado
    }
}
