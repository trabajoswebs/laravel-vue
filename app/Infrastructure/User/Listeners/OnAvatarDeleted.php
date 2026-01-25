<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Listeners;

use App\Application\User\Events\AvatarDeleted;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;

/**
 * Reacciona a la eliminación de avatar limpiando artefactos residuales.
 */
final class OnAvatarDeleted
{
    public function handle(AvatarDeleted $event): void
    {
        // Cleanup defensivo (no aporta artefactos adicionales si ya se limpiaron).
        CleanupMediaArtifactsJob::dispatch([]);
    }
}
