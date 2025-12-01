<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

use Carbon\CarbonImmutable;

interface ClockInterface
{
    public function now(): CarbonImmutable;
}
