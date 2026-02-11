<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PreventBruteForceMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/__test__/pbf/a', static fn () => response()->json(['ok' => 'a']));
        Route::middleware('web')->post('/__test__/pbf/b', static fn () => response()->json(['ok' => 'b']));

        config()->set('security.rate_limiting.general_requests_per_minute', 1);
        config()->set('security.rate_limiting.api_requests_per_minute', 60);
        config()->set('security.rate_limiting.login_max_attempts', 5);
        config()->set('security.rate_limiting.login_decay_minutes', 15);
    }

    public function test_rate_limit_blocks_repeated_post_to_same_route(): void
    {
        $this->postJson('/__test__/pbf/a')->assertOk();
        $this->postJson('/__test__/pbf/a')->assertStatus(429);
    }

    public function test_route_fingerprint_avoids_false_positive_between_different_routes(): void
    {
        $this->postJson('/__test__/pbf/a')->assertOk();

        // Same user/IP should still be allowed on a different endpoint.
        $this->postJson('/__test__/pbf/b')->assertOk();
    }
}
