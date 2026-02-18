<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Policies;

use App\Support\Policies\Concerns\HandlesTenantMembership;
use App\Models\User;
use App\Models\Upload;
use App\Support\Enums\Uploads\ServingMode;
use App\Domain\Uploads\UploadProfileId;
use App\Modules\Uploads\Registry\UploadProfileRegistry;

class UploadPolicy
{
    use HandlesTenantMembership;

    public function create(User $user, string $profileId): bool
    {
        if ($this->hasTenantOverride($user)) {
            return true;
        }

        $tenantId = $this->currentTenantId($user);
        if ($tenantId === null) {
            return false;
        }

        // Debe pertenecer al tenant actual para subir.
        return $user->tenants()->whereKey($tenantId)->exists();
    }

    public function replace(User $user, Upload $upload): bool
    {
        return $this->canOperateOnUpload($user, $upload);
    }

    public function delete(User $user, Upload $upload): bool
    {
        return $this->canOperateOnUpload($user, $upload);
    }

    public function download(User $user, Upload $upload): bool
    {
        if ($this->isForbiddenProfile($upload)) {
            return false;
        }

        return $this->canOperateOnUpload($user, $upload);
    }

    private function canOperateOnUpload(User $user, Upload $upload): bool
    {
        if ($this->hasTenantOverride($user)) {
            return true;
        }

        $tenantId = $this->currentTenantId($user);
        if ($tenantId === null) {
            return false;
        }

        if ((string) $upload->tenant_id !== (string) $tenantId) {
            return false;
        }

        return $user->tenants()->whereKey($tenantId)->exists();
    }

    private function isForbiddenProfile(Upload $upload): bool
    {
        if ((string) $upload->profile_id === 'certificate_secret') {
            return true;
        }

        try {
            $profile = app(UploadProfileRegistry::class)->get(new UploadProfileId((string) $upload->profile_id));
        } catch (\Throwable) {
            return false;
        }

        return $profile->servingMode === ServingMode::FORBIDDEN;
    }

    private function currentTenantId(User $user): int|string|null
    {
        if (function_exists('tenant') && tenant()) {
            return tenant()->getKey();
        }

        return $user->getCurrentTenantId();
    }
}
