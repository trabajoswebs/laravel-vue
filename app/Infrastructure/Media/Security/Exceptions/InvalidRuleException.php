<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Security\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando las reglas YARA no pasan los controles de integridad.
 */
final class InvalidRuleException extends RuntimeException
{
}
