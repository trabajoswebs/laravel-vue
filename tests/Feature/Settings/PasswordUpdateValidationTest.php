<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TenantTestHelpers;
use Tests\TestCase;

final class PasswordUpdateValidationTest extends TestCase
{
    use RefreshDatabase;
    use TenantTestHelpers;

    public function test_password_update_requires_confirmation_match(): void
    {
        [$user, $tenant] = $this->makeTenantUser('Settings Password Validation Tenant');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->getKey()])
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'S@fePass123!',
                'password' => 'N3wSecure!456',
                'password_confirmation' => 'Mismatch!789',
            ])
            ->assertRedirect('/settings/password')
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('S@fePass123!', (string) $user->fresh()->password));
    }
}
