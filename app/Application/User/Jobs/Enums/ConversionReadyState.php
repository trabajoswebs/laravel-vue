<?php

declare(strict_types=1);

namespace App\Application\User\Jobs\Enums;

enum ConversionReadyState: string
{
    case Ready = 'ready';
    case Pending = 'pending';
    case Transient = 'transient';

    public static function fromString(string $value): self
    {
        return match ($value) {
            self::Ready->value => self::Ready,
            self::Pending->value => self::Pending,
            self::Transient->value => self::Transient,
            default => throw new \InvalidArgumentException("Unsupported ready state '{$value}'."),
        };
    }
}
