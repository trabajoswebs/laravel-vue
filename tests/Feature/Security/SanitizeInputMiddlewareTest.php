<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SanitizeInputMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/__test__/sanitize-input', static function (Request $request) {
            return response()->json([
                'email' => $request->input('email'),
                'name' => $request->input('name'),
            ]);
        });
    }

    public function test_invalid_email_is_replaced_with_placeholder(): void
    {
        $response = $this->postJson('/__test__/sanitize-input', [
            'email' => 'john<doe@example.com',
            'name' => 'John Doe',
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'email' => '__invalid_email__',
                'name' => 'John Doe',
            ]);
    }
}
