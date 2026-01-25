<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Quarantine;

/**
 * Representa un artefacto almacenado en cuarentena.
 *
 * Incluye metadatos mínimos para correlacionar logs y TTL sin exponer
 * rutas fuera de la capa de infraestructura.
 */
final class QuarantineToken
{
    /**
     * @param string $path Ruta absoluta del artefacto en cuarentena.
     * @param string $relative Identificador relativo (opaco) dentro de la raíz de cuarentena.
     * @param string|null $correlationId Identificador de correlación para trazabilidad.
     * @param string|null $profile Nombre del perfil (colección) asociado.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $relative,
        public readonly ?string $correlationId = null,
        public readonly ?string $profile = null,
    ) {
    }

    /**
     * Construye un token desde una ruta absoluta conocida.
     *
     * @param string $path Ruta absoluta en cuarentena.
     * @param string $relative Identificador relativo dentro del disco.
     * @param string|null $correlationId Identificador de correlación.
     * @param string|null $profile Perfil asociado.
     */
    public static function fromPath(
        string $path,
        string $relative,
        ?string $correlationId = null,
        ?string $profile = null
    ): self {
        return new self($path, $relative, $correlationId, $profile);
    }

    /**
     * Identificador relativo del artefacto (valor que puede compartirse).
     */
    public function identifier(): string
    {
        return $this->relative;
    }
}
