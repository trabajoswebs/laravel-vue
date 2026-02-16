<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServingRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_serving_honours_rate_limit_by_user(): void
    {
        [$user, $tenant] = $this->makeTenantUser();
        $path = "tenants/{$tenant->id}/users/{$user->id}/avatars/avatar.jpg";

        Storage::fake('local');
        Storage::disk('local')->put($path, 'img');

        config()->set('image-pipeline.avatar_disk', 'local');
        config()->set('filesystems.default', 'local');

        // Reduce the limit for the test to keep it fast.
        RateLimiter::for('media-serving', static function (Request $request): Limit {
            return Limit::perMinute(2)->by('test-user:' . $request->user()->id);
        });
        RateLimiter::clear('test-user:' . $user->id);

        $this->actingAs($user);

        $headers = ['Accept' => 'application/json'];

        $this->get('/media/' . $path, $headers)->assertOk();
        $this->get('/media/' . $path, $headers)->assertOk();

        $response = $this->get('/media/' . $path, $headers);
        $response->assertStatus(429);
    }

    /**
     * @return array{0:User,1:\App\Models\Tenant}
     */
    private function makeTenantUser(): array
    {
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant Rate Limit',
            'owner_user_id' => $user->id,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return [$user, $tenant];
    }
}
