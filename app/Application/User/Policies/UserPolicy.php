<?php

namespace App\Application\User\Policies;

use App\Domain\User\User;
use App\Application\User\Policies\Concerns\HandlesMediaOwnership;

/**
 * Política de Autorización para el Modelo User
 *
 * Esta clase define las reglas de autorización para las operaciones CRUD
 * y acciones específicas relacionadas con los perfiles de usuario. 
 * Implementa un sistema de control de acceso basado en:
 *
 * - Propiedad directa (usuario puede gestionar su propio perfil)
 * - Permisos específicos (users.manage, media.manage)
 * - Roles predefinidos (admin, super-admin, media-admin)
 * - Campo booleano is_admin (compatibilidad con estructuras simples)
 *
 * Características principales:
 * - Separación de permisos para diferentes acciones (view, update, delete)
 * - Gestión específica de medios (avatar) mediante trait
 * - Métodos consistentes con firma (authenticatedUser, targetUser)
 * - Soporte para diferentes sistemas de autorización (Spatie, estructura simple)
 * - Centralización de lógica común en métodos protegidos
 */
class UserPolicy
{
    use HandlesMediaOwnership;

    /**
     * Determina si el usuario autenticado puede ver el perfil del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere ver
     * @return bool                   true si puede ver el perfil, false en caso contrario
     */
    public function view(User $authenticatedUser, User $targetUser): bool
    {
        return $this->viewProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el usuario autenticado puede actualizar el perfil del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere actualizar
     * @return bool                   true si puede actualizar el perfil, false en caso contrario
     */
    public function update(User $authenticatedUser, User $targetUser): bool
    {
        return $this->updateProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el usuario autenticado puede eliminar el perfil del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere eliminar
     * @return bool                   true si puede eliminar el perfil, false en caso contrario
     */
    public function delete(User $authenticatedUser, User $targetUser): bool
    {
        return $this->deleteProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el usuario autenticado puede ver el perfil del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere ver
     * @return bool                   true si puede ver el perfil, false en caso contrario
     */
    public function viewProfile(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el usuario autenticado puede actualizar el perfil del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere actualizar
     * @return bool                   true si puede actualizar el perfil, false en caso contrario
     */
    public function updateProfile(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el usuario autenticado puede eliminar el perfil del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere eliminar
     * @return bool                   true si puede eliminar el perfil, false en caso contrario
     */
    public function deleteProfile(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el actor puede actualizar el avatar del usuario objetivo.
     *
     * Este método delega la verificación al trait HandlesMediaOwnership
     * para mantener la lógica de gestión de medios centralizada.
     *
     * @param User $actor   Usuario que intenta actualizar el avatar
     * @param User $target  Usuario cuyo avatar se quiere actualizar
     * @return bool         true si puede actualizar el avatar, false en caso contrario
     */
    public function updateAvatar(User $actor, User $target): bool
    {
        return $this->canManageMediaOwnership($actor, $target);
    }

    /**
     * Determina si el usuario autenticado puede eliminar el avatar del usuario objetivo.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo avatar se quiere eliminar
     * @return bool                   true si puede eliminar el avatar, false en caso contrario
     */
    public function deleteAvatar(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageMediaOwnership($authenticatedUser, $targetUser);
    }

    /**
     * Verificación centralizada de permisos de gestión de perfil.
     *
     * Este método implementa la lógica común para determinar si un usuario
     * puede gestionar el perfil de otro usuario, aplicando múltiples capas
     * de verificación de autorización.
     *
     * @param User $authenticatedUser Usuario autenticado que intenta la acción
     * @param User $targetUser        Usuario cuyo perfil se quiere gestionar
     * @return bool                   true si puede gestionar el perfil, false en caso contrario
     */
    protected function canManageProfile(User $authenticatedUser, User $targetUser): bool
    {
        // Acceso directo: el usuario puede gestionar su propio perfil
        if ($authenticatedUser->is($targetUser)) {
            return true;
        }

        // Verificar permiso específico de gestión de usuarios
        if (
            method_exists($authenticatedUser, 'hasPermissionTo') &&
            $authenticatedUser->hasPermissionTo('users.manage')
        ) {
            return true;
        }

        // Verificar roles que permiten gestión de usuarios
        if (
            method_exists($authenticatedUser, 'hasAnyRole') &&
            $authenticatedUser->hasAnyRole(['admin', 'super-admin'])
        ) {
            return true;
        }

        // Verificar campo booleano de administrador (fallback)
        if (($authenticatedUser->is_admin ?? false)) {
            return true;
        }

        return false;
    }
}
