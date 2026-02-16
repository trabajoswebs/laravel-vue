<?php

namespace Tests\Feature\Uploads;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UploadRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_upload_rate_limit_hits_429(): void
    {
        // Arrange: route with tenant + rate limit, config to 1 attempt
        Route::middleware(['tenant', 'rate.uploads'])->post('/__test/avatar', static fn () => new JsonResponse(['ok' => true]));
        Config::set('image-pipeline.rate_limit.max_attempts', 1);
        Config::set('image-pipeline.rate_limit.decay_seconds', 60);

        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Tenant RL',
            'owner_user_id' => $user->id,
        ]);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Act: first request passes, second should be rate limited
        $this->actingAs($user)->postJson('/__test/avatar')->assertOk();
        $this->actingAs($user)->postJson('/__test/avatar')->assertStatus(429);
    }
}
