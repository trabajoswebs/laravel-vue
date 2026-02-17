<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Contracts;

use App\Modules\Uploads\Contracts\MediaProfile;
use App\Modules\Uploads\Pipeline\DTO\InternalPipelineResult;
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

