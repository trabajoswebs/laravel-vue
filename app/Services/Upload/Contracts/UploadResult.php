<?php

declare(strict_types=1);

namespace App\Services\Upload\Contracts;

/**
 * DTO mínimo para resultados de pipelines de subida.
 *
 * Permite transportar el archivo final y metadata relevante sin
 * acoplarse a una implementación concreta.
 */
final class UploadResult
{
    /**
     * @param string      $path      Ruta o clave del artefacto persistido.
     * @param string|null $checksum  Hash opcional para deduplicar o auditar.
     * @param array       $metadata  Datos adicionales (mime real, dimensiones, flags).
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $checksum = null,
        public readonly array $metadata = [],
    ) {
    }
}
