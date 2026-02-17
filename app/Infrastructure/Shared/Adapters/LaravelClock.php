<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Adapters;

use App\Support\Contracts\ClockInterface;
use Carbon\CarbonImmutable;

final class LaravelClock implements ClockInterface
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
