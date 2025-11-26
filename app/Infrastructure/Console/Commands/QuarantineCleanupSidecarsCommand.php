<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Infrastructure\Media\Upload\Core\QuarantineRepository;
use Illuminate\Console\Command;

class QuarantineCleanupSidecarsCommand extends Command
{
    protected $signature = 'quarantine:cleanup-sidecars';

    protected $description = 'Remove orphaned quarantine hash sidecar files.';

    public function __construct(private readonly QuarantineRepository $repository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!method_exists($this->repository, 'cleanupOrphanedSidecars')) {
            $this->error('Quarantine repository does not support sidecar cleanup.');
            return Command::FAILURE;
        }

        $cleaned = $this->repository->cleanupOrphanedSidecars();

        $this->info("Cleaned {$cleaned} orphaned sidecar file(s).");

        return Command::SUCCESS;
    }
}
