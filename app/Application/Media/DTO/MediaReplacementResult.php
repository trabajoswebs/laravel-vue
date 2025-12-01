<?php

declare(strict_types=1);

namespace App\Application\Media\DTO;

use App\Domain\Media\Contracts\MediaResource;
use App\Domain\Media\DTO\ConversionExpectations;
use App\Domain\Media\DTO\MediaReplacementSnapshot;

/**
 * Resultado agnóstico de reemplazo de media.
 */
final class MediaReplacementResult
{
    private function __construct(
        public readonly MediaResource $media,
        public readonly MediaReplacementSnapshot $snapshot,
        public readonly ConversionExpectations $expectations,
    ) {}

    public static function make(
        MediaResource $media,
        MediaReplacementSnapshot $snapshot,
        ConversionExpectations $expectations
    ): self {
        return new self($media, $snapshot, $expectations);
    }
}
