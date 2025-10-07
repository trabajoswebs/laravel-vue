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
     * Centralized check for profile management permissions.
     */
    protected function canManageProfile(User $authenticatedUser, User $targetUser): bool
    {
        if ($authenticatedUser->is($targetUser)) {
            return true;
        }

        if (method_exists($authenticatedUser, 'hasPermissionTo') &&
            $authenticatedUser->hasPermissionTo('users.manage')) {
            return true;
        }

        if (method_exists($authenticatedUser, 'hasAnyRole') &&
            $authenticatedUser->hasAnyRole(['admin', 'super-admin'])) {
            return true;
        }

        if (property_exists($authenticatedUser, 'is_admin') && $authenticatedUser->is_admin) {
            return true;
        }

        return false;
    }
}