<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Exceptions;

use Throwable;

/**
 * Se lanza cuando un scanner no puede completarse (timeout, error, etc.).
 */
final class ScanFailedException extends UploadException
{
    /**
     * @param string $message Mensaje de la excepción.
     * @param string|null $scanner Nombre del escáner que falló.
     * @param Throwable|null $previous Excepción anterior.
     */
    public function __construct(
        string $message = 'Failed to scan uploaded file.',
        private readonly ?string $scanner = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Obtiene el nombre del escáner que falló.
     *
     * @return string|null Nombre del escáner o null si no se especificó.
     */
    public function scanner(): ?string
    {
        return $this->scanner;
    }
}