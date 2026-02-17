<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Adapters;

use App\Support\Contracts\AsyncJobDispatcherInterface;
use App\Application\User\Jobs\CleanupMediaArtifacts;
use App\Infrastructure\Uploads\Pipeline\Jobs\CleanupMediaArtifactsJob;
use DateTimeInterface;

/**
 * Adaptador del despachador de jobs asíncronos de Laravel.
 * 
 * Convierte jobs de dominio en jobs específicos de Laravel
 * antes de despacharlos al sistema de colas del framework.
 */
final class LaravelAsyncJobDispatcher implements AsyncJobDispatcherInterface
{
    /**
     * Despacha un job asíncrono al sistema de colas.
     *
     * @param object $job Job a despachar (puede ser de dominio o de Laravel)
     * @param DateTimeInterface|null $delay Retraso opcional antes de ejecutar el job
     */
    public function dispatch(object $job, ?DateTimeInterface $delay = null): void
    {
        if ($job instanceof CleanupMediaArtifacts) {
            $job = new CleanupMediaArtifactsJob($job->artifacts, $job->preserveMediaIds);  // Convierte job de dominio a job de Laravel
        }

        $pending = dispatch($job);  // Despacha el job al sistema de colas de Laravel

        if ($delay !== null) {
            $pending->delay($delay);  // Aplica retraso si se especifica
        }
    }
}
