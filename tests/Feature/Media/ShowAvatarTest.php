<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\TemporaryUrlFilesystem;
use Tests\TestCase;

class ShowAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_avatar_serving_sets_configured_cache_control(): void
    {
        config()->set('media.signed_serve.enabled', true);
        config()->set('media-serving.local_max_age_seconds', 120);

        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $user->id,
        ]);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $tenant->makeCurrent();

        $media = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'collection_name' => 'avatar',
            'name' => 'avatar',
            'file_name' => 'avatar.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 1,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->id],
            'generated_conversions' => ['thumb' => true],
            'responsive_images' => [],
        ]);

        Storage::fake('public');
        $conversionPath = $media->getPathRelativeToRoot('thumb');
        Storage::disk('public')->put($conversionPath, 'img');

        $url = URL::signedRoute('media.avatar.show', ['media' => $media->id, 'c' => 'thumb']);
        $response = $this->actingAs($user)->get($url);

        $response->assertOk();
        $header = $response->headers->get('Cache-Control');
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('max-age=120', $header);
        $this->assertStringContainsString('must-revalidate', $header);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_s3_avatar_serving_sets_no_store_cache_control_on_redirect(): void
    {
        config()->set('media.signed_serve.enabled', true);
        config()->set('media-serving.temporary_url_ttl_seconds', 300);
        config()->set('filesystems.disks.s3.driver', 's3');

        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $user->id,
        ]);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $tenant->makeCurrent();

        $media = Media::query()->create([
            'model_type' => User::class,
            'model_id' => $user->id,
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'collection_name' => 'avatar',
            'name' => 'avatar',
            'file_name' => 'avatar.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 's3',
            'conversions_disk' => 's3',
            'size' => 1,
            'manipulations' => [],
            'custom_properties' => ['tenant_id' => $tenant->id],
            'generated_conversions' => ['thumb' => true],
            'responsive_images' => [],
        ]);

        $conversionPath = $media->getPathRelativeToRoot('thumb');
        $adapter = new TemporaryUrlFilesystem('https://s3.test/avatar', [$conversionPath]);
        Storage::shouldReceive('disk')->with('s3')->andReturn($adapter);

        $url = URL::signedRoute('media.avatar.show', ['media' => $media->id, 'c' => 'thumb']);
        $response = $this->actingAs($user)->get($url);

        $response->assertStatus(302);
        $header = $response->headers->get('Cache-Control');
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('max-age=0', $header);
        $this->assertStringContainsString('no-store', $header);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

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
