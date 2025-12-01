<?php

declare(strict_types=1);

namespace App\Infrastructure\Security\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Se lanza cuando un escáner antivirus no puede ejecutarse de forma segura.
 */
final class AntivirusException extends RuntimeException
{
    public function __construct(
        private readonly string $scanner,
        private readonly string $reason,
        ?Throwable $previous = null,
    ) {
        $message = sprintf('Antivirus scanner "%s" failed: %s', $scanner, $reason);
        parent::__construct($message, previous: $previous);
    }

    public function scanner(): string
    {
        return $this->scanner;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
