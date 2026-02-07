<?php

namespace Tests\Feature\Media;

use App\Infrastructure\Models\User;
use App\Infrastructure\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class AvatarRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_avatar_route_uses_media_serving_rate_limiter(): void
    {
        // Habilita serving firmado
        config()->set('media.signed_serve.enabled', true);
        config()->set('image-pipeline.avatar_disk', 'public');
        config()->set('filesystems.default', 'public');
        config()->set('filesystems.cloud', 'public');

        // Límite bajo y controlado solo para este test
        RateLimiter::for('media-serving', static function (Request $request): array {
            $key = 'test-media:' . ($request->user()?->getAuthIdentifier() ?? $request->ip());
            return [Limit::perMinute(2)->by($key)];
        });

        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = Tenant::query()->create([
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

        $url = URL::temporarySignedRoute(
            'media.avatar.show',
            now()->addMinutes(5),
            ['media' => $media->id, 'c' => 'thumb']
        );

        // Primeras dos peticiones deben pasar
        $headers = ['Accept' => 'application/json'];
        RateLimiter::clear('test-media:' . $user->id);

        $this->actingAs($user)->get($url, $headers)->assertOk();
        $this->actingAs($user)->get($url, $headers)->assertOk();

        // La tercera debe golpear el rate limit (429)
        $this->actingAs($user)->get($url, $headers)->assertStatus(429);
    }
}
