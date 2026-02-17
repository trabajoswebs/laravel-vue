<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Contracts;

/**
 * Coordina el cleanup de artefactos de media tras conversions.
 */
interface MediaCleanupScheduler
{
    /**
     * @param array<int,string> $expectedConversions
     */
    public function flagPendingConversions(MediaResource $media, array $expectedConversions): void;

    /**
     * @param array<string,list<string>> $artifacts
     * @param array<int,string|int> $preserveMediaIds
     */
    public function scheduleCleanup(
        MediaResource $triggerMedia,
        array $artifacts,
        array $preserveMediaIds,
        array $conversions = []
    ): void;

    public function handleConversionEvent(MediaResource $media): void;

    public function flushExpired(string $mediaId): void;

    public function purgeExpired(?int $maxAgeHours = null, int $chunkSize = 100): int;
}
