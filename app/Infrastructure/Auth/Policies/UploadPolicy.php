<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Policies;

use App\Infrastructure\Auth\Policies\Concerns\HandlesTenantMembership;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Core\Models\Upload;
use App\Domain\Uploads\ServingMode;

/**
 * Policy para descargas de Uploads.
 */
class UploadPolicy
{
    use HandlesTenantMembership;

    /**
     * Autoriza la creación de un upload genérico.
     */
    public function create(User $user, string $profileId): bool
    {
        if ($this->hasTenantOverride($user)) {
            return true;
        }

        $tenantId = $user->getCurrentTenantId();
        if ($tenantId === null) {
            return false;
        }

        return $user->tenants()->whereKey($tenantId)->exists();
    }

    /**
     * Autoriza el reemplazo de un upload existente.
     */
    public function replace(User $user, Upload $upload): bool
    {
        return $this->canAccessUpload($user, $upload);
    }

    /**
     * Autoriza la eliminación de un upload existente.
     */
    public function delete(User $user, Upload $upload): bool
    {
        return $this->canAccessUpload($user, $upload);
    }

    /**
     * Autoriza la descarga de un upload.
     */
    public function download(User $user, Upload $upload): bool
    {
        if ($upload->profile_id === 'certificate_secret') { // Nunca permitir secretos
            return false;
        }

        if ($this->hasTenantOverride($user)) { // Admin/override pueden cruzar tenants
            return true;
        }

        $tenantId = $user->getCurrentTenantId();
        if ($tenantId === null || (string) $upload->tenant_id !== (string) $tenantId) { // Debe coincidir tenant activo
            return false;
        }

        return $user->tenants()->whereKey($upload->tenant_id)->exists(); // Pertenece al tenant
    }

    private function canAccessUpload(User $user, Upload $upload): bool
    {
        if ($this->hasTenantOverride($user)) {
            return true;
        }

        $tenantId = $user->getCurrentTenantId();
        if ($tenantId === null || (string) $upload->tenant_id !== (string) $tenantId) {
            return false;
        }

        return $user->tenants()->whereKey($upload->tenant_id)->exists();
    }
}
