<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Application\Media\Contracts\MediaOwner;

/**
 * Puerto de aplicación para recopilar artefactos asociados a media.
 */
interface MediaArtifactCollector
{
    /**
     * @return array<int, array{media: mixed, artifacts: array<string, list<string>>}>
     */
    public function collect(MediaOwner $owner, string $collection, array $types = []): array;

    /**
     * @return array<int, array{media: mixed, disks: array<string, array{
     *     original: array{path: ?string, exists: bool},
     *     conversions: array{path: ?string, exists: bool},
     *     responsive: array{path: ?string, exists: bool},
     * }>} >
     */
    public function collectDetailed(MediaOwner $owner, string $collection, array $types = []): array;
}
