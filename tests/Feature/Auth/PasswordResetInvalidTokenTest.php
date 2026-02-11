<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PasswordResetInvalidTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_fails_with_invalid_token_and_preserves_password_hash(): void
    {
        $user = User::factory()->create();
        $originalHash = (string) $user->password;

        $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'N3wPass!12345',
            'password_confirmation' => 'N3wPass!12345',
        ])->assertSessionHasErrors('email');

        $this->assertSame($originalHash, (string) $user->fresh()->password);
        $this->assertGuest();
    }
}
