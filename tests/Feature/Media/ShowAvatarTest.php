<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ShowAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_tenant_signed_avatar_is_denied(): void
    {
        // Setup tenants/users and media in different tenants
        $tenantAUser = User::factory()->create(['current_tenant_id' => null]); // User owner in tenant A
        $tenantA = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $tenantAUser->id,
        ]); // Tenant A row
        $tenantAUser->forceFill(['current_tenant_id' => $tenantA->id])->save(); // Set current tenant
        $tenantAUser->tenants()->attach($tenantA->id, ['role' => 'owner']); // Pivot membership tenant A
        $media = Media::query()->create([ // Media record in avatar collection for tenant A user
            'model_type' => User::class,
            'model_id' => $tenantAUser->id,
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'collection_name' => 'avatar',
            'name' => 'avatar',
            'file_name' => 'v1.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 1,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => ['thumb' => false],
            'responsive_images' => [],
        ]);

        $tenantBUser = User::factory()->create(['current_tenant_id' => null]); // User in tenant B
        $tenantB = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant B',
            'owner_user_id' => $tenantBUser->id,
        ]); // Tenant B row
        $tenantBUser->forceFill(['current_tenant_id' => $tenantB->id])->save(); // Set current tenant
        $tenantBUser->tenants()->attach($tenantB->id, ['role' => 'owner']); // Pivot membership tenant B

        $url = URL::signedRoute('media.avatar.show', ['media' => $media->id, 'c' => 'thumb']); // Signed URL to avatar

        // Act: user from different tenant hits signed URL
        $response = $this->actingAs($tenantBUser)->get($url);

        // Assert: access is forbidden before file lookup
        $response->assertStatus(403);
    }
}
