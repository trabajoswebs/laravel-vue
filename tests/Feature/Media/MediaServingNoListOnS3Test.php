<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServingNoListOnS3Test extends TestCase
{
    use RefreshDatabase;

    public function test_missing_conversion_on_s3_does_not_list_directory(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant 1',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        $path = "tenants/{$tenant->id}/users/{$user->id}/avatars/uuid/conversions/thumb.webp";

        Storage::fake('s3');
        config()->set('image-pipeline.avatar_disk', 's3');
        config()->set('media-library.disk_name', 's3');
        config()->set('filesystems.cloud', 's3');
        config()->set('filesystems.disks.s3.driver', 's3');

        $this->actingAs($user);

        $response = $this->get('/media/' . $path);

        // Expect 404 (no listing fallback), but ensure no exception from files() call
        $response->assertStatus(404);
    }
}
