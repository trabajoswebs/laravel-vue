<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

use App\Domain\Media\Contracts\MediaResource;

/**
 * Puerto de aplicación para programar y ejecutar limpieza de artefactos.
 */
interface MediaCleanupScheduler
{
    /**
     * @param array<int,string> $expectedConversions
     */
    public function flagPendingConversions(MediaResource $media, array $expectedConversions): void;

    /**
     * @param array<string,list<array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int,string|int|null> $preserveMediaIds
     * @param array<int,string> $expectedConversions
     */
    public function scheduleCleanup(
        MediaResource $triggerMedia,
        array $artifacts,
        array $preserveMediaIds,
        array $expectedConversions
    ): void;

    public function handleConversionEvent(MediaResource $media): void;

    public function flushExpired(string $mediaId): void;

    public function purgeExpired(?int $maxAgeHours = null, int $chunkSize = 100): int;
}
