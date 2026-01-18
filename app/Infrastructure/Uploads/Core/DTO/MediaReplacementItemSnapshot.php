<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\DTO;

final class MediaReplacementItemSnapshot
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public readonly string $disk,
        public readonly array $paths,
        public readonly string|int|null $mediaId = null,
    ) {
    }

    /**
     * @param list<string> $paths
     */
    public static function make(string $disk, array $paths, string|int|null $mediaId = null): self
    {
        return new self($disk, array_values($paths), $mediaId);
    }
}
