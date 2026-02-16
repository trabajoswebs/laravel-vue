<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePageNoStorageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_does_not_render_public_storage_links(): void
    {
        // Arrange: user + tenant membership
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::query()->create([
            'name' => 'Profile Tenant',
            'owner_user_id' => $user->id,
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        // Act
        $response = $this->actingAs($user)->get('/settings/profile');

        // Assert
        $response->assertOk();
        $response->assertDontSee('/storage/');
        $response->assertDontSee('/storage');
        $response->assertDontSee('%2Fstorage%2F');
        $response->assertDontSee('/storage%2F'); // encoded variant

        // Asserts positivos sólo si existe avatar_url en props
        $payload = $response->original->getData()['page']['props'] ?? [];
        $avatarUrl = $payload['auth']['user']['avatar_url'] ?? null;
        if ($avatarUrl !== null) {
            $this->assertMatchesRegularExpression(
                '#^(https?://|/media/)|^https://#',
                $avatarUrl,
                'avatar_url debe ser temporal (https) o /media/'
            );
        }

        // No debe colarse /storage en ningún prop serializado de la página
        $jsonProps = json_encode($payload);
        $this->assertStringNotContainsString('/storage', $jsonProps);
    }
}
