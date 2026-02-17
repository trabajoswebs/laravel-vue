<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Sanitization;

use App\Support\Sanitization\DisplayName;
use Tests\TestCase;

final class DisplayNameTest extends TestCase
{
    public function test_xss_payload_is_not_accepted_as_valid_display_name(): void
    {
        $displayName = DisplayName::from('<script>alert(1)</script>');

        $this->assertFalse($displayName->isValid());
        $this->assertNull($displayName->sanitizedOrNull());
    }

    public function test_valid_display_name_is_preserved(): void
    {
        $displayName = DisplayName::from('Jane Doe');

        $this->assertTrue($displayName->isValid());
        $this->assertSame('Jane Doe', $displayName->sanitized());
    }
}
