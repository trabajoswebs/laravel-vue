<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenancyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_tenant_is_created_on_registration(): void
    {
        // Arrange: payload de registro válido
        $payload = [
            'name' => 'Tenant User',
            'email' => 'tenant@example.com',
            'password' => 'S@fePass123!',
            'password_confirmation' => 'S@fePass123!',
        ];

        // Act: ejecutar registro
        $response = $this->post('/register', $payload);

        // Assert: se crea tenant personal y se marca current_tenant_id
        $user = User::first();
        $tenant = Tenant::first();

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertNotNull($user);
        $this->assertNotNull($tenant);
        $this->assertSame($tenant->getKey(), $user->current_tenant_id);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
        ]);
    }

    public function test_request_is_denied_when_user_not_member_of_current_tenant(): void
    {
        // Arrange: ruta protegida por grupo tenant
        Route::middleware('tenant')
            ->get('/__test/tenant-guard', static fn () => 'ok');

        // Arrange: usuario con current_tenant_id apuntando a tenant ajeno
        $foreignOwner = User::factory()->create();
        $foreignTenant = Tenant::query()->create([
            'name' => 'Ajeno',
            'owner_user_id' => $foreignOwner->id,
        ]);

        $user = User::factory()->create(['current_tenant_id' => $foreignTenant->id]);

        // Act & Assert: la solicitud debe responder 403 por falta de membresía
        $this->actingAs($user)
            ->get('/__test/tenant-guard')
            ->assertStatus(403);
    }

    public function test_invalid_tenant_session_is_blocked(): void
    {
        // Arrange: ruta protegida para validar sesión tenant
        Route::middleware('tenant')
            ->get('/__test/tenant-session', static fn () => 'ok');

        // Arrange: usuario con tenant válido pero sesión manipulada
        $owner = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Propio',
            'owner_user_id' => $owner->id,
        ]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

        $otherTenant = Tenant::query()->create([
            'name' => 'Otro',
            'owner_user_id' => $owner->id,
        ]);

        // Act & Assert: la sesión inválida debe producir 403
        $this->actingAs($owner)
            ->withSession(['tenant_id' => $otherTenant->id])
            ->get('/__test/tenant-session')
            ->assertStatus(403);
    }

    public function test_tenant_resolves_from_current_tenant_id_membership(): void
    {
        // Arrange: ruta que expone el tenant resuelto
        Route::middleware('tenant')
            ->get('/__test/tenant-current', static fn () => response()->json(['tenant_id' => tenant()?->getKey()]));

        // Arrange: usuario con membresía válida y current_tenant_id apuntando a ese tenant
        $user = User::factory()->create();
        $tenant = Tenant::query()->create([
            'name' => 'Tenant válido',
            'owner_user_id' => $user->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        // Act & Assert: la request resuelve tenant y responde 200 con el id correcto
        $this->actingAs($user)
            ->getJson('/__test/tenant-current')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    }

    public function test_command_bootstrap_existing_users_is_idempotent(): void
    {
        // Arrange: crea usuario sin tenant asignado
        $user = User::factory()->create(['current_tenant_id' => null]);

        // Act: ejecuta el comando dos veces
        $this->artisan('tenancy:bootstrap-existing-users')->assertSuccessful();
        $this->artisan('tenancy:bootstrap-existing-users')->assertSuccessful();

        // Assert: solo se crea un tenant y el usuario queda asociado como owner y con current_tenant_id
        $tenantCount = Tenant::query()->count();
        $pivotCount = DB::table('tenant_user')->count();

        $this->assertSame(1, $tenantCount);
        $this->assertSame(1, $pivotCount);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'current_tenant_id' => Tenant::first()->id,
        ]);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => Tenant::first()->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }
}
