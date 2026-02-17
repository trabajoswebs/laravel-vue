<?php

declare(strict_types=1);

namespace App\Modules\Uploads\DTO;

use App\Modules\Uploads\Contracts\MediaResource;

final class MediaReplacementResult
{
    public function __construct(
        public readonly MediaResource $media,
        public readonly ?MediaReplacementSnapshot $snapshot = null,
        public readonly ?ConversionExpectations $expectations = null,
    ) {
    }

    public static function make(
        MediaResource $media,
        ?MediaReplacementSnapshot $snapshot = null,
        ?ConversionExpectations $expectations = null
    ): self {
        return new self($media, $snapshot, $expectations);
    }
}
