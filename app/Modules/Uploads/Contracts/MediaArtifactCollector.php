<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Contracts;

/**
 * Recolector de artefactos asociados a medias existentes.
 */
interface MediaArtifactCollector
{
    /**
     * @return array<int, array{media: MediaResource, artifacts: array<string, list<string>>}>
     */
    public function collect(MediaOwner $owner, string $collection, array $types = []): array;

    /**
     * @return array<int, array{media: MediaResource, disks: array<string, array<string, array{path:?string,exists:bool}>}>>
     */
    public function collectDetailed(MediaOwner $owner, string $collection, array $types = []): array;
}
