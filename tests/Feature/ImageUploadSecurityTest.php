<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ImageUploadSecurityTest extends TestCase
{
    public function test_rate_limit_uploads_middleware_blocks_after_threshold(): void
    {
        Route::middleware('rate.uploads')->post('/__test/uploads', static fn () => response()->json(['ok' => true]));

        Config::set('image-pipeline.rate_limit.max_attempts', 1);
        Config::set('image-pipeline.rate_limit.decay_seconds', 60);

        $user = new User();
        $user->forceFill([
            'id' => 999,
            'email' => 'rate-test@example.com',
        ]);

        $rateKey = 'img_upload:999';
        RateLimiter::clear($rateKey);

        $this->actingAs($user)
            ->postJson('/__test/uploads')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->actingAs($user)
            ->postJson('/__test/uploads')
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'errors']);

        RateLimiter::clear($rateKey);
    }
}
