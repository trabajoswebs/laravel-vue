<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TemporaryUrlFilesystem;
use Tests\TestCase;

final class MediaServingCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_serving_local_sets_configured_cache_control(): void
    {
        [$user, $tenant] = $this->makeTenantUser();
        $path = "tenants/{$tenant->id}/users/{$user->id}/avatars/avatar.jpg";

        Storage::fake('local');
        Storage::disk('local')->put($path, 'img');

        $this->actingAs($user);
        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');
        config()->set('media-serving.local_max_age_seconds', 120);

        $response = $this->get('/media/' . $path);

        $response->assertOk();
        $header = $response->headers->get('Cache-Control');
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('max-age=120', $header);
        $this->assertStringContainsString('must-revalidate', $header);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_media_serving_s3_sets_no_store_cache_control_on_redirect(): void
    {
        [$user, $tenant] = $this->makeTenantUser();
        $path = "tenants/{$tenant->id}/users/{$user->id}/avatars/avatar.jpg";

        $adapter = new TemporaryUrlFilesystem('https://s3.test/temp', [$path]);
        Storage::shouldReceive('disk')->with('s3')->andReturn($adapter);

        $this->actingAs($user);
        config()->set('image-pipeline.avatar_disk', 's3');
        config()->set('media-library.disk_name', 's3');
        config()->set('filesystems.default', 's3');
        config()->set('filesystems.cloud', 's3');
        config()->set('filesystems.disks.s3.driver', 's3');

        $response = $this->get('/media/' . $path);

        $response->assertStatus(302);
        $header = $response->headers->get('Cache-Control');
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', $header);
        $this->assertStringContainsString('max-age=0', $header);
        $this->assertStringContainsString('no-store', $header);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @return array{0:User,1:\App\Infrastructure\Tenancy\Models\Tenant}
     */
    private function makeTenantUser(): array
    {
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant Cache',
            'owner_user_id' => $user->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return [$user, $tenant];
    }
}
