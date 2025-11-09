<?php

use App\Support\Media\Services\MediaCleanupScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('media-cleanup:purge {--ttl= : Maximum age in hours before a state is considered stale} {--chunk=100 : Batch size for database iteration}', function (MediaCleanupScheduler $scheduler) {
    $ttlOption = $this->option('ttl');
    $chunkOption = (int) $this->option('chunk');

    $ttl = $ttlOption !== null ? (int) $ttlOption : null;
    $chunk = $chunkOption > 0 ? $chunkOption : 100;

    $purged = $scheduler->purgeExpired($ttl, $chunk);

    if ($purged === 0) {
        $this->info('No stale media cleanup states found.');
        return 0;
    }

    $hours = $ttl ?? (int) config('media.cleanup_state_ttl_hours', 48);
    $this->info("Purged {$purged} media cleanup states older than {$hours} hour(s).");

    return 0;
})->purpose('Purge stale media cleanup states and dispatch any pending cleanup payloads.');
