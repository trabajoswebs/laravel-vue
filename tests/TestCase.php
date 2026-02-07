<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Tests\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        // Evita fallos por CSRF en pruebas funcionales; la seguridad se valida en tests dedicados.
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }
}
