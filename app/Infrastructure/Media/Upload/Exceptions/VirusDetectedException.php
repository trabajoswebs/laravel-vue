<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Exceptions;

/**
 * Se lanza cuando un escáner antivirus detecta un payload malicioso.
 */
final class VirusDetectedException extends UploadException
{
    /**
     * @param string $message Mensaje de la excepción.
     * @param string|null $scanner Nombre del escáner que detectó la amenaza.
     * @param array<int, string> $signatures Firmas que activaron la detección.
     */
    public function __construct(
        string $message = 'Malicious content detected in upload.',
        private readonly ?string $scanner = null,
        private readonly array $signatures = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Obtiene el nombre del escáner que detectó la amenaza.
     *
     * @return string|null Nombre del escáner o null si no se especificó.
     */
    public function scanner(): ?string
    {
        return $this->scanner;
    }

    /**
     * Obtiene las firmas que activaron la detección.
     *
     * @return array<int, string> Lista de firmas.
     */
    public function signatures(): array
    {
        return $this->signatures;
    }
}