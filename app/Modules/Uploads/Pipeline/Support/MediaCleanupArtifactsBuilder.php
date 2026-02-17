<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Construye artefactos (original + conversions + responsive) para cleanup dirigido.
 *
 * @return array<string,list<array{dir:string,mediaId:string}>>
 */
final class MediaCleanupArtifactsBuilder
{
    public function __construct(
        private readonly PathGenerator $pathGenerator,
    ) {}

    /**
     * @return array<string,list<array{dir:string,mediaId:string}>>
     */
    public function forMedia(Media $media): array
    {
        $disk = (string) ($media->disk ?? '');
        if ($disk === '') {
            return [];
        }

        $conversionDisk = (string) ($media->conversions_disk ?: $media->disk);
        $mediaId = (string) $media->getKey();

        $baseDir = rtrim($this->pathGenerator->getPath($media), '/');
        $convDir = rtrim($this->pathGenerator->getPathForConversions($media), '/');
        $respDir = rtrim($this->pathGenerator->getPathForResponsiveImages($media), '/');

        $artifacts = [
            $disk => [],
        ];

        if ($baseDir !== '') {
            $artifacts[$disk][] = ['dir' => $baseDir, 'mediaId' => $mediaId];
        }

        if ($conversionDisk !== '') {
            $artifacts[$conversionDisk] = array_merge($artifacts[$conversionDisk] ?? [], array_values(array_filter([
                $convDir !== '' ? ['dir' => $convDir, 'mediaId' => $mediaId] : null,
                $respDir !== '' ? ['dir' => $respDir, 'mediaId' => $mediaId] : null,
            ])));
        }

        return array_filter(
            $artifacts,
            static fn(array $entries): bool => $entries !== []
        );
    }
}
