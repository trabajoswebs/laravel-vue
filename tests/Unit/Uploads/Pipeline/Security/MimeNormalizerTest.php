<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Security;

use App\Modules\Uploads\Pipeline\Security\MimeNormalizer;
use Tests\TestCase;

final class MimeNormalizerTest extends TestCase
{
    public function test_normalize_alias_and_strip_parameters(): void
    {
        $this->assertSame('image/jpeg', MimeNormalizer::normalize('IMAGE/JPG; charset=binary'));
    }

    public function test_normalize_rejects_invalid_mime_shape_without_runtime_warning(): void
    {
        $this->assertNull(MimeNormalizer::normalize('text[path]'));
    }

    public function test_normalize_accepts_valid_mime_with_symbols(): void
    {
        $this->assertSame('application/ld+json', MimeNormalizer::normalize('application/ld+json'));
    }

    public function test_normalize_maps_pkcs12_aliases_to_canonical_value(): void
    {
        $this->assertSame('application/x-pkcs12', MimeNormalizer::normalize('application/pkcs12'));
        $this->assertSame('application/x-pkcs12', MimeNormalizer::normalize('application/x-pkcs-12'));
    }
}
