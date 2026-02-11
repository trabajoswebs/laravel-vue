<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantTestHelpers;
use Tests\TestCase;

final class ProfileUpdateSanitizationTest extends TestCase
{
    use RefreshDatabase;
    use TenantTestHelpers;

    public function test_profile_update_rejects_display_name_with_xss_payload(): void
    {
        [$user, $tenant] = $this->makeTenantUser('Settings Sanitization Tenant');
        $originalName = (string) $user->name;

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->getKey()])
            ->from('/settings/profile')
            ->patch('/settings/profile', [
                'name' => '<script>alert(1)</script>',
                'email' => $user->email,
            ])
            ->assertRedirect('/settings/profile')
            ->assertSessionHasErrors('name');

        $this->assertSame($originalName, (string) $user->fresh()->name);
    }
}
