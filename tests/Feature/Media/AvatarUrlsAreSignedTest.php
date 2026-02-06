<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class AvatarUrlsAreSignedTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_avatar_urls_use_signed_route_and_not_media_or_storage(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Infrastructure\Tenancy\Models\Tenant::query()->create([
            'name' => 'Tenant A',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        Storage::fake('public');
        config()->set('image-pipeline.avatar_disk', 'public');
        config()->set('media-library.disk_name', 'public');
        config()->set('media.signed_serve.enabled', true);

        // Crear media avatar minimal
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

        $freshUser = $user->fresh();

        $avatarUrl = $freshUser->avatar_url;
        $thumbUrl = $freshUser->avatar_thumb_url;

        $this->assertNotNull($avatarUrl);
        $this->assertNotNull($thumbUrl);

        foreach ([$avatarUrl, $thumbUrl] as $url) {
            $this->assertStringContainsString('/media/avatar', $url);
            $this->assertStringNotContainsString('/media/tenants/', $url);
            $this->assertStringNotContainsString('/storage/', $url);
            $this->assertStringContainsString('signature=', $url);
        }
    }
}
