<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Security;

use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use App\Infrastructure\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Support\Contracts\LoggerInterface;
use App\Infrastructure\Uploads\Pipeline\Security\MagicBytesValidator;
use Tests\TestCase;

final class MagicBytesValidatorTest extends TestCase
{
    public function test_accepts_valid_jpeg_signature_and_mime(): void
    {
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAVEAEBAAAAAAAAAAAAAAAAAAAAEf/aAAwDAQACEAMQAAAB7gD/xAAUEAEAAAAAAAAAAAAAAAAAAAAQ/9oACAEBAAEFAp//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAEDAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAECAQE/AV//2Q==', true);
        $this->assertIsString($jpeg);
        $path = $this->tempFileWithBytes($jpeg);

        try {
            $validator = new MagicBytesValidator(new NullMagicLogger());
            $validator->validate($path, new FileConstraints(), ['correlation_id' => 'cid-1']);

            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_non_image_signature_even_when_magic_matches(): void
    {
        $path = $this->tempFileWithBytes("%PDF-1.7\n" . str_repeat('A', 64));

        try {
            $validator = new MagicBytesValidator(new NullMagicLogger());

            $this->expectException(UploadValidationException::class);
            $validator->validate($path, new FileConstraints(), ['correlation_id' => 'cid-2']);
        } finally {
            @unlink($path);
        }
    }

    public function test_skips_validation_when_strict_magic_bytes_is_disabled(): void
    {
        $original = config('image-pipeline.enforce_strict_magic_bytes');
        config()->set('image-pipeline.enforce_strict_magic_bytes', false);

        $path = $this->tempFileWithBytes('plain text payload');

        try {
            $validator = new MagicBytesValidator(new NullMagicLogger());
            $validator->validate($path, new FileConstraints(), ['correlation_id' => 'cid-3']);

            $this->assertTrue(true);
        } finally {
            config()->set('image-pipeline.enforce_strict_magic_bytes', $original);
            @unlink($path);
        }
    }

    public function test_accepts_jpeg_signature_label_alias(): void
    {
        $originalSignatures = config('image-pipeline.allowed_magic_signatures');
        config()->set('image-pipeline.allowed_magic_signatures', [
            'ffd8ff' => 'jpg',
        ]);

        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAAQABADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAVEAEBAAAAAAAAAAAAAAAAAAAAEf/aAAwDAQACEAMQAAAB7gD/xAAUEAEAAAAAAAAAAAAAAAAAAAAQ/9oACAEBAAEFAp//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAEDAQE/AV//xAAUEQEAAAAAAAAAAAAAAAAAAAAQ/9oACAECAQE/AV//2Q==', true);
        $this->assertIsString($jpeg);
        $path = $this->tempFileWithBytes($jpeg);

        try {
            $validator = new MagicBytesValidator(new NullMagicLogger());
            $validator->validate($path, new FileConstraints(), ['correlation_id' => 'cid-jpg-alias']);

            $this->assertTrue(true);
        } finally {
            config()->set('image-pipeline.allowed_magic_signatures', $originalSignatures);
            @unlink($path);
        }
    }

    private function tempFileWithBytes(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'magic_');
        $this->assertIsString($path);
        file_put_contents($path, $bytes);

        return $path;
    }
}

final class NullMagicLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
}
