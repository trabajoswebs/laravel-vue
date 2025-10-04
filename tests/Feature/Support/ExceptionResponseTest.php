<?php

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExceptionResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test__/boom', function () {
            throw new \RuntimeException('Boom');
        })->middleware('web');
    }

    public function test_json_requests_receive_error_identifier(): void
    {
        $response = $this->getJson('/__test__/boom');

        $response->assertStatus(500);
        $response->assertJsonStructure(['message', 'error_id']);
        $this->assertNotEmpty($response->json('error_id'));
        $this->assertSame(
            $response->json('error_id'),
            $response->headers->get('X-Error-Id')
        );
    }
}
