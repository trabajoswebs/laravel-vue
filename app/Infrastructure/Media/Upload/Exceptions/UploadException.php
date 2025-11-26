<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Excepción base para errores en pipelines de subida.
 */
abstract class UploadException extends RuntimeException
{
    /**
     * Crea una excepción genérica a partir de otra.
     *
     * @param string $message Mensaje de la nueva excepción.
     * @param Throwable $previous Excepción anterior.
     * @return UnexpectedUploadException Nueva instancia de excepción.
     */
    public static function fromThrowable(string $message, Throwable $previous): UnexpectedUploadException
    {
        return new UnexpectedUploadException($message, previous: $previous);
    }
}