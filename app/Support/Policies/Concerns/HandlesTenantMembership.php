<?php // Trait para verificar membresía de tenant en policies

declare(strict_types=1); // Activa tipado estricto

namespace App\Support\Policies\Concerns; // Namespace de traits de policies

use App\Models\User; // Modelo de usuario

/**
 * Centraliza validaciones de pertenencia a tenant.
 */
trait HandlesTenantMembership // Proporciona helpers de tenant
{
    /**
     * Verifica si el actor puede operar sobre el target considerando tenant.
     *
     * @param User $actor Usuario autenticado que actúa
     * @param User $target Usuario afectado
     * @return bool true si comparte tenant o tiene override
     */
    protected function sharesTenantOrHasOverride(User $actor, User $target): bool // Revisa pertenencia o privilegio
    {
        if ($this->hasTenantOverride($actor)) { // Si tiene privilegios globales
            return true; // Permite saltar restricción de tenant
        }

        $tenantId = $actor->getCurrentTenantId(); // Lee tenant_id activo del actor

        if ($tenantId === null) { // Si no hay tenant activo
            return false; // No puede operar
        }

        return $target->tenants()->whereKey($tenantId)->exists(); // Verifica que el target pertenezca al mismo tenant
    }

    /**
     * Determina si el actor tiene privilegios para cruzar tenants.
     *
     * @param User $actor Usuario autenticado
     * @return bool true si tiene override
     */
    protected function hasTenantOverride(User $actor): bool // Evalúa roles/permisos globales
    {
        if (method_exists($actor, 'hasAnyRole') && $actor->hasAnyRole(['admin', 'super-admin'])) { // Roles elevados
            return true; // Tiene override por rol
        }

        if (method_exists($actor, 'hasPermissionTo') && $actor->hasPermissionTo('tenants.override')) { // Permiso específico
            return true; // Tiene override por permiso
        }

        return (bool) ($actor->is_admin ?? false); // Fallback simple usando flag is_admin
    }
}
