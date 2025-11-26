<?php

namespace Tests\Feature\Language;

use App\Domain\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LanguageEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_change_language(): void
    {
        $user = User::factory()->create();

        $rateLimitKey = 'language-change:' . $user->id;
        RateLimiter::clear($rateLimitKey);

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->withHeader('X-Inertia', 'true')
            ->post('/language/change/en');

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('success', true);
        $this->assertSame('en', session('locale'));

        RateLimiter::clear($rateLimitKey);
    }

    public function test_current_language_endpoint_returns_metadata(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/language/current');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'locale',
                'fallbackLocale',
                'supported',
                'metadata' => ['name', 'native_name', 'flag', 'direction'],
                'serverTranslations' => [
                    'locale',
                    'fallbackLocale',
                    'supported',
                    'metadata' => ['name', 'native_name', 'flag', 'direction'],
                ],
            ],
        ]);
    }

    public function test_change_language_rejects_invalid_locale(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->withHeader('X-Inertia', 'true')
            ->post('/language/change/invalid!');

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error');
        $this->assertSame('en', session('locale', config('app.locale')));
    }

    public function test_change_language_honours_rate_limit(): void
    {
        $user = User::factory()->create();

        Config::set('security.rate_limiting.language_change_max_attempts', 1);
        Config::set('security.rate_limiting.language_change_decay_minutes', 10);

        $rateLimitKey = 'language-change:' . $user->id;
        RateLimiter::clear($rateLimitKey);

        $this->actingAs($user)
            ->from('/dashboard')
            ->withHeader('X-Inertia', 'true')
            ->post('/language/change/en')
            ->assertRedirect('/dashboard');

        $secondAttempt = $this->actingAs($user)
            ->from('/dashboard')
            ->withHeader('X-Inertia', 'true')
            ->post('/language/change/es');

        $secondAttempt->assertRedirect('/dashboard');
        $secondAttempt->assertSessionHas('error');
        $this->assertTrue(RateLimiter::tooManyAttempts($rateLimitKey, 1));

        RateLimiter::clear($rateLimitKey);
    }
}
