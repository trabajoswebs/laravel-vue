<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UploadsCrudRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_uploads_returns_429_after_threshold(): void
    {
        [$user, $rateKey] = $this->makeTenantUserAndRateKey();
        RateLimiter::clear($rateKey);

        config()->set('image-pipeline.rate_limit.max_attempts', 1);
        config()->set('image-pipeline.rate_limit.decay_seconds', 60);

        $this->actingAs($user)
            ->postJson('/uploads', [])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/uploads', [])
            ->assertStatus(429);
    }

    public function test_patch_uploads_returns_429_after_threshold(): void
    {
        [$user, $rateKey] = $this->makeTenantUserAndRateKey();
        RateLimiter::clear($rateKey);

        config()->set('image-pipeline.rate_limit.max_attempts', 1);
        config()->set('image-pipeline.rate_limit.decay_seconds', 60);

        $uploadId = (string) Str::uuid();

        $this->actingAs($user)
            ->patchJson("/uploads/{$uploadId}", [])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patchJson("/uploads/{$uploadId}", [])
            ->assertStatus(429);
    }

    public function test_delete_uploads_returns_429_after_threshold(): void
    {
        [$user, $rateKey] = $this->makeTenantUserAndRateKey();
        RateLimiter::clear($rateKey);

        config()->set('image-pipeline.rate_limit.max_attempts', 1);
        config()->set('image-pipeline.rate_limit.decay_seconds', 60);

        $uploadId = (string) Str::uuid();

        $this->actingAs($user)
            ->deleteJson("/uploads/{$uploadId}")
            ->assertStatus(404);

        $this->actingAs($user)
            ->deleteJson("/uploads/{$uploadId}")
            ->assertStatus(429);
    }

    /**
     * @return array{0:User,1:string}
     */
    private function makeTenantUserAndRateKey(): array
    {
        $user = User::factory()->create(['current_tenant_id' => null]);
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Uploads RL',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return [$user, 'img_upload:' . $user->id];
    }
}

