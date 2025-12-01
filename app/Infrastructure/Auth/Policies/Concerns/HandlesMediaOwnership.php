<?php

namespace App\Infrastructure\Auth\Policies\Concerns;

use App\Infrastructure\Models\User;

/**
 * Trait para centralizar la verificación de propiedad de medios
 *
 * Este trait encapsula la lógica común de verificación de propiedad y permisos
 * sobre medios (como avatares, imágenes, archivos adjuntos) en la aplicación.
 * Proporciona métodos reutilizables que pueden ser compartidos entre diferentes
 * políticas que manejan recursos multimedia, manteniendo la lógica cohesiva
 * y facilitando la extensión cuando se añaden nuevas acciones relacionadas
 * con medios (exportar medios, eliminar adjuntos, etc.).
 *
 * Características:
 * - Verificación de propiedad directa (usuario es el dueño)
 * - Soporte para permisos y roles elevados
 * - Compatible con diferentes sistemas de autorización
 * - Fácil de mantener y extender
 */
trait HandlesMediaOwnership
{
    /**
     * Determina si el actor puede gestionar medios asociados al propietario.
     *
     * Este método implementa la lógica principal de verificación de permisos:
     * - El propietario puede gestionar sus propios medios (acceso directo)
     * - Usuarios con privilegios elevados pueden gestionar medios de otros
     *
     * @param User $actor Usuario que intenta realizar la acción
     * @param User $owner Usuario propietario del medio
     * @return bool true si el actor puede gestionar los medios, false en caso contrario
     */
    protected function canManageMediaOwnership(User $actor, User $owner): bool
    {
        if ($actor->is($owner)) {
            // El propietario siempre puede gestionar sus propios medios
            return true;
        }

        // Verificar si el actor tiene privilegios elevados
        return $this->hasElevatedMediaPrivileges($actor);
    }

    /**
     * Verificación reutilizable de privilegios globales de medios.
     *
     * Este método implementa múltiples capas de verificación de privilegios:
     * 1. Verifica permiso específico 'media.manage' (compatible con Spatie Laravel-permission)
     * 2. Verifica roles específicos que permiten gestión de medios
     * 3. Verifica campo booleano is_admin (para compatibilidad con estructuras tradicionales)
     *
     * @param User $actor Usuario a verificar
     * @return bool true si el actor tiene privilegios elevados para gestión de medios
     */
    private function hasElevatedMediaPrivileges(User $actor): bool
    {
        // Verificar permiso específico de gestión de medios
        if (
            method_exists($actor, 'hasPermissionTo') &&
            $actor->hasPermissionTo('media.manage')
        ) {
            return true;
        }

        // Verificar roles que permiten gestión de medios
        if (
            method_exists($actor, 'hasAnyRole') &&
            $actor->hasAnyRole(['media-admin', 'admin', 'super-admin'])
        ) {
            return true;
        }

        // Verificar campo booleano de administrador (fallback para estructuras simples)
        if (($actor->is_admin ?? false)) {
            return true;
        }

        return false;
    }
}
