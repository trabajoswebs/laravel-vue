<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the authenticated user can view the profile resource.
     */
    public function view(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determine whether the authenticated user can update the profile resource.
     */
    public function update(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determine whether the authenticated user can delete the profile resource.
     */
    public function delete(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Determina si el usuario autenticado puede actualizar el avatar del usuario objetivo.
     *
     * @param User $actor Usuario que intenta realizar la acción
     * @param User $target Usuario cuyo avatar se quiere actualizar
     * @return bool
     */
    public function updateAvatar(User $actor, User $target): bool
    {
        return $this->canManageProfile($actor, $target);
    }

    /**
     * Determine whether the authenticated user can delete the profile resource.
     */
    public function deleteAvatar(User $authenticatedUser, User $targetUser): bool
    {
        return $this->canManageProfile($authenticatedUser, $targetUser);
    }

    /**
     * Centralized check for profile management permissions.
     */
    protected function canManageProfile(User $authenticatedUser, User $targetUser): bool
    {
        if ($authenticatedUser->is($targetUser)) {
            return true;
        }

        if (
            method_exists($authenticatedUser, 'hasPermissionTo') &&
            $authenticatedUser->hasPermissionTo('users.manage')
        ) {
            return true;
        }

        if (
            method_exists($authenticatedUser, 'hasAnyRole') &&
            $authenticatedUser->hasAnyRole(['admin', 'super-admin'])
        ) {
            return true;
        }

        if (($authenticatedUser->is_admin ?? false)) {
            return true;
        }

        return false;
    }
}
