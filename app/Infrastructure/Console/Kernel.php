<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Infrastructure\Console\Commands\CleanAuditLogs;
use App\Infrastructure\Console\Commands\QuarantineCleanupSidecarsCommand;
use App\Infrastructure\Console\Commands\QuarantinePruneCommand;
use App\Infrastructure\Console\Commands\TenancyBootstrapExistingUsers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Registro explícito de comandos de consola.
     *
     * Evita que comandos críticos queden fuera si fallara la auto-carga.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        CleanAuditLogs::class,
        QuarantineCleanupSidecarsCommand::class,
        QuarantinePruneCommand::class,
        TenancyBootstrapExistingUsers::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Example: schedule quarantine cleanup weekly
        $schedule->command('quarantine:prune --hours=24')->daily();
        $schedule->command('quarantine:cleanup-sidecars')->weekly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
