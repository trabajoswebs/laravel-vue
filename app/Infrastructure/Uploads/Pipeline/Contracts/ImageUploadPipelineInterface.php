<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Contracts;

use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Pipeline\DTO\InternalPipelineResult;
use Illuminate\Http\UploadedFile;
use SplFileObject;

interface ImageUploadPipelineInterface
{
    public function process(
        UploadedFile|SplFileObject|string $source,
        MediaProfile $profile,
        string $correlationId
    ): InternalPipelineResult;
}

