<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Exceptions;

/**
 * Se lanza cuando el artefacto en cuarentena cambia o pierde integridad.
 */
final class QuarantineIntegrityException extends QuarantineException
{
    /**
     * @param string $message Mensaje de la excepción.
     * @param string|null $expectedHash Hash esperado del archivo.
     * @param string|null $actualHash Hash actual del archivo.
     */
    public function __construct(
        string $message = 'Quarantine artifact integrity check failed.',
        private readonly ?string $expectedHash = null,
        private readonly ?string $actualHash = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Obtiene el hash esperado del archivo.
     *
     * @return string|null Hash esperado o null si no se especificó.
     */
    public function expectedHash(): ?string
    {
        return $this->expectedHash;
    }

    /**
     * Obtiene el hash actual del archivo.
     *
     * @return string|null Hash actual o null si no se especificó.
     */
    public function actualHash(): ?string
    {
        return $this->actualHash;
    }
}