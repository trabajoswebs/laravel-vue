<?php // TenantFinder que resuelve por usuario autenticado

declare(strict_types=1); // Habilita tipado estricto

namespace App\Infrastructure\Tenancy\TenantFinder; // Namespace para finders de tenant

use App\Infrastructure\Tenancy\Models\Tenant; // Modelo Tenant propio
use Illuminate\Http\Request; // Request HTTP
use Illuminate\Support\Facades\Auth; // Facade de autenticación

/**
 * Resuelve el tenant actual a partir del usuario autenticado.
 */
class AuthUserTenantFinder // Implementa lógica de finder sin depender de Spatie
{
    /**
     * Obtiene el tenant aplicable para la request.
     *
     * @param Request $request Request HTTP entrante
     * @return Tenant|null Tenant encontrado o null si no corresponde
     */
    public function findForRequest(Request $request): ?Tenant // Resuelve el tenant usando auth()->user()
    {
        $user = Auth::user(); // Obtiene el usuario autenticado actual

        if ($user === null) { // Si no hay usuario autenticado
            return null; // No se puede resolver tenant
        }

        $tenantId = $user->getCurrentTenantId(); // Lee el tenant_id actual del usuario

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
