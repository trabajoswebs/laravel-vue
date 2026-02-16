<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServingAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_path_is_allowed_and_served(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant 1',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        $path = "tenants/{$tenant->id}/users/{$user->id}/avatars/avatar.jpg";

        Storage::fake('local');
        Storage::disk('local')->put($path, 'img');

        $this->actingAs($user);

        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $response = $this->get('/media/' . $path);

        $response->assertOk();
        $response->assertHeader('Cache-Control');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_path_outside_allowlist_returns_404_even_if_exists(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant 1',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        $path = "tenants/{$tenant->id}/secret.txt";

        Storage::fake('local');
        Storage::disk('local')->put($path, 'secret');

        $this->actingAs($user);

        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $response = $this->get('/media/' . $path);

        $response->assertStatus(404);
    }

    public function test_wrong_tenant_prefix_returns_404_instead_of_403(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant 2',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        $wrongTenantPath = "tenants/999/users/{$user->id}/avatars/avatar.jpg";

        Storage::fake('local');
        Storage::disk('local')->put($wrongTenantPath, 'img');

        $this->actingAs($user);

        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $response = $this->get('/media/' . $wrongTenantPath);

        $response->assertStatus(404);
    }

    public function test_path_traversal_attempt_returns_404(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant Traversal',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        $traversalPath = "tenants/{$tenant->id}/users/{$user->id}/avatars/../../secrets.txt";

        Storage::fake('local');
        Storage::disk('local')->put("tenants/{$tenant->id}/secrets.txt", 'secret');

        $this->actingAs($user);

        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $response = $this->get('/media/' . $traversalPath);

        $response->assertStatus(404);
    }

    public function test_missing_conversion_falls_back_to_exact_base_name_only(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant Exact Match',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        Storage::fake('local');
        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        $dir = "tenants/{$tenant->id}/users/{$user->id}/avatars/uuid";
        $requested = "{$dir}/conversions/v123-thumb.webp";
        Storage::disk('local')->put("{$dir}/av1234.jpg", 'wrong');
        Storage::disk('local')->put("{$dir}/v123.jpg", 'correct');

        $this->actingAs($user);
        $response = $this->get('/media/' . $requested);

        $response->assertOk();
        $this->assertSame('correct', $response->streamedContent());
    }
}
