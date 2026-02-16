<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Models\Tenant;

trait TenantTestHelpers
{
    /**
     * @return array{0:User,1:Tenant}
     */
    protected function makeTenantUser(string $name = 'Tenant Test'): array
    {
        $user = User::factory()->create(['current_tenant_id' => null]);

        $tenant = Tenant::query()->create([
            'name' => $name,
            'owner_user_id' => $user->getKey(),
        ]);

        $user->tenants()->attach($tenant->getKey(), ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->getKey()])->save();

        return [$user, $tenant];
    }
}
