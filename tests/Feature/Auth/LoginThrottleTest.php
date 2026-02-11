<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Security\Rules\RateLimitSignatureRules;
use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_throttled_after_configured_failed_attempts(): void
    {
        config()->set('security.rate_limiting.login_max_attempts', 1);
        config()->set('security.rate_limiting.login_decay_minutes', 10);

        $rules = app(RateLimitSignatureRules::class);
        $key = $rules->forLogin(null, '127.0.0.1');
        RateLimiter::clear($key);

        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'WrongPass999!',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'WrongPass999!',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(RateLimiter::tooManyAttempts($key, 1));
        $this->assertGuest();

        RateLimiter::clear($key);
    }
}
