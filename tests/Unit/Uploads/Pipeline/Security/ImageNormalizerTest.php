<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Security;

use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use App\Infrastructure\Uploads\Pipeline\Security\ImageNormalizer;
use Tests\TestCase;

final class ImageNormalizerTest extends TestCase
{
    public function test_reencode_rejects_images_exceeding_megapixel_limit(): void
    {
        config()->set('image-pipeline.max_megapixels', 1.0);
        config()->set('image-pipeline.max_edge', 10000);
        config()->set('image-pipeline.max_bytes', 10_000_000);

        $normalizer = new ImageNormalizer(new FileConstraints(), null, 90);

        $image = new class {
            public function width(): int { return 2000; }
            public function height(): int { return 2000; }
            public function toPng(int $quality): string { return 'png-bytes'; }
        };

        $this->assertNull($normalizer->reencode($image, 'image/png'));
    }

    public function test_reencode_accepts_dimensions_within_limits(): void
    {
        config()->set('image-pipeline.max_megapixels', 48.0);
        config()->set('image-pipeline.max_edge', 16384);
        config()->set('image-pipeline.max_bytes', 10_000_000);

        $normalizer = new ImageNormalizer(new FileConstraints(), null, 90);

        $image = new class {
            public function width(): int { return 512; }
            public function height(): int { return 512; }
            public function toPng(int $quality): string { return 'png-bytes'; }
        };

        $this->assertSame('png-bytes', $normalizer->reencode($image, 'image/png'));
    }

    public function test_reencode_rejects_images_exceeding_max_edge(): void
    {
        config()->set('image-pipeline.max_megapixels', 100.0);
        config()->set('image-pipeline.max_edge', 512);
        config()->set('image-pipeline.max_bytes', 10_000_000);

        $normalizer = new ImageNormalizer(new FileConstraints(), null, 90);

        $image = new class {
            public function width(): int { return 1024; }
            public function height(): int { return 256; }
            public function toPng(int $quality): string { return 'png-bytes'; }
        };

        $this->assertNull($normalizer->reencode($image, 'image/png'));
    }

    public function test_reencode_prefers_jpeg_encoder_for_jpeg_mime(): void
    {
        config()->set('image-pipeline.max_megapixels', 48.0);
        config()->set('image-pipeline.max_edge', 16384);
        config()->set('image-pipeline.max_bytes', 10_000_000);

        $normalizer = new ImageNormalizer(new FileConstraints(), null, 90);

        $image = new class {
            public function width(): int { return 512; }
            public function height(): int { return 512; }
            public function toPng(int $quality): string { return 'png-bytes'; }
            public function toJpeg(int $quality): string { return 'jpeg-bytes'; }
        };

        $this->assertSame('jpeg-bytes', $normalizer->reencode($image, 'image/jpeg'));
    }

    public function test_reencode_normalizes_non_jpeg_mime_to_png_encoder(): void
    {
        config()->set('image-pipeline.max_megapixels', 48.0);
        config()->set('image-pipeline.max_edge', 16384);
        config()->set('image-pipeline.max_bytes', 10_000_000);

        $normalizer = new ImageNormalizer(new FileConstraints(), null, 90);

        $image = new class {
            public function width(): int { return 512; }
            public function height(): int { return 512; }
            public function toPng(int $quality): string { return 'png-bytes'; }
            public function toJpeg(int $quality): string { return 'jpeg-bytes'; }
        };

        $this->assertSame('png-bytes', $normalizer->reencode($image, 'image/webp'));
    }

    public function test_reencode_png_path_uses_png_encoder_and_avoids_jpeg_reencode(): void
    {
        config()->set('image-pipeline.max_megapixels', 48.0);
        config()->set('image-pipeline.max_edge', 16384);
        config()->set('image-pipeline.max_bytes', 10_000_000);

        $normalizer = new ImageNormalizer(new FileConstraints(), null, 90);

        $jpegCalled = false;
        $image = new class($jpegCalled) {
            public function __construct(private bool &$jpegCalled) {}
            public function width(): int { return 256; }
            public function height(): int { return 256; }
            public function toPng(int $quality): string { return 'png-alpha-preserved'; }
            public function toJpeg(int $quality): string
            {
                $this->jpegCalled = true;
                return 'jpeg-bytes';
            }
        };

        $this->assertSame('png-alpha-preserved', $normalizer->reencode($image, 'image/png'));
        $this->assertFalse($jpegCalled);
    }
}
