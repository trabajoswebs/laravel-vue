<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Adapters;

use App\Application\Shared\Contracts\ClockInterface;
use Carbon\CarbonImmutable;

final class LaravelClock implements ClockInterface
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
