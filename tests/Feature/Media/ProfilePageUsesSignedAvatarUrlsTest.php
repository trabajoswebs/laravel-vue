<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ProfilePageUsesSignedAvatarUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_uses_signed_avatar_urls_not_media_or_storage(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        Storage::fake('public');
        config()->set('image-pipeline.avatar_disk', 'public');
        config()->set('media-library.disk_name', 'public');
        config()->set('media.signed_serve.enabled', true);

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
            'custom_properties' => [],
            'generated_conversions' => ['thumb' => true, 'large' => true],
            'responsive_images' => [],
        ]);

        $response = $this->actingAs($user)->get('/settings/profile');

        $response->assertOk();
        $response->assertDontSee('/storage/');
        $response->assertDontSee('/media/tenants/');

        $payload = $response->original->getData()['page']['props'] ?? [];
        $avatarUrl = $payload['auth']['user']['avatar_url'] ?? null;
        $thumbUrl = $payload['auth']['user']['avatar_thumb_url'] ?? null;

        $this->assertNotNull($avatarUrl);
        $this->assertNotNull($thumbUrl);
        $this->assertStringContainsString('/media/avatar', $avatarUrl);
        $this->assertStringContainsString('/media/avatar', $thumbUrl);
        $this->assertStringContainsString('signature=', $avatarUrl);
        $this->assertStringContainsString('signature=', $thumbUrl);
    }
}
