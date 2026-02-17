<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Pipeline\Support;

use App\Modules\Uploads\Contracts\FileConstraints;
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Support\QuarantineManager;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class QuarantineManagerTest extends TestCase
{
    public function test_validate_mime_type_accepts_alias_when_canonical_is_allowed(): void
    {
        config()->set('image-pipeline.allowed_mimes', ['image/jpeg']);
        config()->set('image-pipeline.disallowed_mimes', []);
        $this->app->instance(FileConstraints::class, new FileConstraints());

        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('image/jpg');

        $manager = new QuarantineManager($this->createMock(QuarantineRepository::class));
        $manager->validateMimeType($file);

        $this->assertTrue(true);
    }

    public function test_validate_mime_type_rejects_when_alias_is_disallowed_even_if_allowed(): void
    {
        config()->set('image-pipeline.allowed_mimes', ['image/jpeg']);
        config()->set('image-pipeline.disallowed_mimes', ['image/jpeg']);
        $this->app->instance(FileConstraints::class, new FileConstraints());

        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('image/jpg');

        $manager = new QuarantineManager($this->createMock(QuarantineRepository::class));

        $this->expectException(UploadValidationException::class);
        $manager->validateMimeType($file);
    }
}

