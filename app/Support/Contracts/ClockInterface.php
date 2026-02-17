<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use Carbon\CarbonImmutable;

interface ClockInterface
{
    public function now(): CarbonImmutable;
}
