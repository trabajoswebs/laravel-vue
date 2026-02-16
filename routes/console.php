<?php

use App\Infrastructure\Uploads\Core\Contracts\MediaCleanupScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Infrastructure\Console\Commands\TenancyBootstrapExistingUsers;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

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

// Registrar el comando tenancy:bootstrap-existing-users en entornos que usan routes/console.php
Artisan::command('tenancy:bootstrap-existing-users {--chunk=100 : Tamaño de chunk para procesar usuarios}', function (int $chunk) {
    $chunkSize = max(10, $chunk);
    $created = 0;
    $attached = 0;

    User::query()
        ->orderBy('id')
        ->chunkById($chunkSize, function ($users) use (&$created, &$attached): void {
            foreach ($users as $user) {
                DB::transaction(function () use ($user, &$created, &$attached): void {
                    $fresh = User::query()->lockForUpdate()->find($user->getKey());

                    if (! $fresh || $fresh->getCurrentTenantId() !== null) {
                        return;
                    }

                    $tenant = Tenant::query()->where('owner_user_id', $fresh->getKey())->first();

                    if (! $tenant) {
                        $tenant = Tenant::query()->create([
                            'name' => "{$fresh->name}'s tenant",
                            'owner_user_id' => $fresh->getKey(),
                        ]);
                        $created++;
                    }

                    $fresh->tenants()->syncWithoutDetaching([$tenant->getKey() => ['role' => 'owner']]);
                    $fresh->forceFill(['current_tenant_id' => $tenant->getKey()])->saveQuietly();
                    $attached++;
                });
            }
        });

    $this->info("Tenants creados: {$created}");
    $this->info("Usuarios actualizados: {$attached}");

    return 0;
})->purpose('Crea tenants personales para usuarios sin current_tenant_id de forma idempotente');
