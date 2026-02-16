<?php

declare(strict_types=1);

namespace Tests\Feature\Language;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InertiaLocaleShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_inertia_props_share_session_locale(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'es'])
            ->get('/dashboard');

        $response->assertOk();

        $props = $response->original->getData()['page']['props'] ?? [];
        $this->assertSame('es', $props['serverTranslations']['locale'] ?? null);
    }
}
