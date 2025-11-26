<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Exceptions;

use Throwable;

/**
 * Se lanza cuando la validación de negocio del archivo falla (tipo, dimensiones, etc.).
 */
final class UploadValidationException extends UploadException
{
    /**
     * @param string $message Mensaje de la excepción.
     * @param Throwable|null $previous Excepción anterior.
     */
    public function __construct(
        string $message = 'Uploaded file failed validation checks.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}