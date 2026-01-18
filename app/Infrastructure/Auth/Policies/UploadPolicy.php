<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Policies;

use App\Infrastructure\Auth\Policies\Concerns\HandlesTenantMembership;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Core\Models\Upload;

/**
 * Policy para descargas de Uploads.
 */
class UploadPolicy
{
    use HandlesTenantMembership;

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
}
