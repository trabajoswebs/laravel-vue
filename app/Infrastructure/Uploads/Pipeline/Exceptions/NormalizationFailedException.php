<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Exceptions;

use Throwable;

/**
 * Se lanza cuando la normalización/re-encode falla por archivo corrupto o incompatibilidad.
 */
final class NormalizationFailedException extends UploadException
{
    /**
     * @param string $message Mensaje de la excepción.
     * @param Throwable|null $previous Excepción anterior.
     */
    public function __construct(
        string $message = 'Unable to normalize uploaded file.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}