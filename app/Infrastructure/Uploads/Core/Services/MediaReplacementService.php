<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Services;

use App\Infrastructure\Uploads\Core\Contracts\MediaArtifactCollector;
use App\Infrastructure\Uploads\Core\Contracts\MediaCleanupScheduler;
use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;
use App\Infrastructure\Uploads\Core\Contracts\MediaProfile;
use App\Infrastructure\Uploads\Core\Contracts\MediaUploader;
use App\Infrastructure\Uploads\Core\Contracts\UploadedMedia;
use App\Infrastructure\Uploads\Core\Contracts\MediaResource;
use App\Infrastructure\Uploads\Core\DTO\MediaReplacementResult;
use App\Infrastructure\Uploads\Core\DTO\MediaReplacementSnapshot;
use App\Infrastructure\Uploads\Core\DTO\MediaReplacementItemSnapshot;

/**
 * Orquesta reemplazo de media y prepara snapshots básicos para cleanup.
 */
final class MediaReplacementService
{
    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly MediaArtifactCollector $artifactCollector,
        private readonly MediaCleanupScheduler $cleanupScheduler,
    ) {
    }

    public function replace(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): MediaResource {
        return $this->uploader->uploadSync($owner, $file, $profile, $correlationId);
    }

    public function replaceWithSnapshot(
        MediaOwner $owner,
        UploadedMedia $file,
        MediaProfile $profile,
        ?string $correlationId = null
    ): MediaReplacementResult {
        $previousArtifacts = $this->artifactCollector->collect($owner, $profile->collection());
        $snapshot = $this->snapshotFromArtifacts($previousArtifacts);
        $media = $this->replace($owner, $file, $profile, $correlationId);

        if ($previousArtifacts !== []) {
            foreach ($previousArtifacts as $entry) {
                $previousMedia = $entry['media'] ?? null;
                $pathsByDisk = $entry['artifacts'] ?? [];

                if (!$previousMedia instanceof MediaResource || !is_array($pathsByDisk) || $pathsByDisk === []) {
                    continue;
                }

                $artifacts = $this->enrichArtifactsWithMediaId($pathsByDisk, (string) $previousMedia->getKey());
                if ($artifacts === []) {
                    continue;
                }

                // La limpieza debe esperar las conversiones del media anterior (no del nuevo).
                $this->cleanupScheduler->scheduleCleanup($previousMedia, $artifacts, [], $profile->conversions());
            }
        }

        return MediaReplacementResult::make($media, $snapshot, null);
    }

    /**
     * @param array<int,array{media:MediaResource,artifacts:array<string,list<string>>}> $artifacts
     */
    private function snapshotFromArtifacts(array $artifacts): ?MediaReplacementSnapshot
    {
        if ($artifacts === []) {
            return null;
        }

        $items = [];
        foreach ($artifacts as $entry) {
            $media = $entry['media'] ?? null;
            if (!$media instanceof MediaResource) {
                continue;
            }
            $pathsByDisk = $entry['artifacts'] ?? [];
            foreach ($pathsByDisk as $disk => $paths) {
                $paths = is_array($paths) ? $paths : [];
                $items[] = MediaReplacementItemSnapshot::make(
                    (string) $disk,
                    $paths,
                    $media->getKey(),
                );
            }
        }

        return MediaReplacementSnapshot::fromItems($items);
    }

    /**
     * @param array<string, list<string>> $pathsByDisk
     * @return array<string, list<array{dir:string,mediaId:string}>>
     */
    private function enrichArtifactsWithMediaId(array $pathsByDisk, string $mediaId): array
    {
        $result = [];

        foreach ($pathsByDisk as $disk => $paths) {
            if (!is_array($paths) || $paths === []) {
                continue;
            }

            foreach ($paths as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $result[(string) $disk][] = [
                    'dir' => $path,
                    'mediaId' => $mediaId,
                ];
            }
        }

        return $result;
    }
}
