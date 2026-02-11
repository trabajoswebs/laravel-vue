<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use App\Infrastructure\Tenancy\TenantFinder\AuthUserTenantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AuthUserTenantFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_finder_returns_current_tenant_for_member_user(): void
    {
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = Tenant::query()->create([
            'name' => 'Finder Tenant',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(static fn () => $user);

        $resolved = (new AuthUserTenantFinder())->findForRequest($request);

        $this->assertInstanceOf(Tenant::class, $resolved);
        $this->assertSame((string) $tenant->getKey(), (string) $resolved?->getKey());
    }

    public function test_finder_returns_null_when_user_is_not_member_of_current_tenant(): void
    {
        $owner = User::factory()->create(['current_tenant_id' => null]);
        $foreignTenant = Tenant::query()->create([
            'name' => 'Foreign Finder Tenant',
            'owner_user_id' => $owner->id,
        ]);

        $user = User::factory()->create(['current_tenant_id' => $foreignTenant->id]);

        $request = Request::create('/dashboard', 'GET');
        $request->setUserResolver(static fn () => $user);

        $resolved = (new AuthUserTenantFinder())->findForRequest($request);

        $this->assertNull($resolved);
    }
}
