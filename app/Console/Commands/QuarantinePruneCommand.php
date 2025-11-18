<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Upload\Core\QuarantineRepository;
use Illuminate\Console\Command;

class QuarantinePruneCommand extends Command
{
    protected $signature = 'quarantine:prune {--hours=24 : Maximum age (in hours) for artifacts}';

    protected $description = 'Remove stale quarantine artifacts older than the given TTL.';

    public function __construct(private readonly QuarantineRepository $repository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        if (!method_exists($this->repository, 'pruneStaleFiles')) {
            $this->error('Quarantine repository does not support pruning.');
            return Command::FAILURE;
        }

        $removed = $this->repository->pruneStaleFiles($hours);

        $this->info("Pruned {$removed} quarantine artifact(s) older than {$hours} hour(s).");

        return Command::SUCCESS;
    }
}
