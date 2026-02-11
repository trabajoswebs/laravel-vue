<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function test_html_response_includes_core_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy');
        $response->assertHeader('Permissions-Policy');
    }
}
