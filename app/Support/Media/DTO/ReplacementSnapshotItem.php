<?php

declare(strict_types=1);

namespace App\Support\Media\DTO;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Artefactos asociados a un media específico dentro del snapshot.
 *
 * @immutable
 */
final class ReplacementSnapshotItem
{
    /**
     * @param array<string,list<string>> $artifacts
     */
    private function __construct(
        public readonly Media $media,
        public readonly array $artifacts,
    ) {}

    /**
     * @param array<string,list<string>> $artifacts
     */
    public static function fromLegacy(Media $media, array $artifacts): self
    {
        $normalized = [];

        foreach ($artifacts as $disk => $paths) {
            if (!is_string($disk) || $disk === '' || !is_array($paths)) {
                continue;
            }

            $paths = array_values(array_filter(
                array_map(
                    static fn ($path) => is_string($path) ? trim($path) : null,
                    $paths
                ),
                static fn (?string $path) => $path !== null && $path !== ''
            ));

            if ($paths === []) {
                continue;
            }

            $normalized[$disk] = $paths;
        }

        return new self($media, $normalized);
    }

    public function hasArtifacts(): bool
    {
        return $this->artifacts !== [];
    }
}
