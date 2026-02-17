<?php

declare(strict_types=1);

namespace App\Support\Contracts;

interface AsyncJobDispatcherInterface
{
    /**
     * @param object $job
     * @param \DateTimeInterface|null $delay
     */
    public function dispatch(object $job, ?\DateTimeInterface $delay = null): void;
}
