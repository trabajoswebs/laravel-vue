<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Upload\Contracts;

/**
 * DTO mínimo para resultados de pipelines de subida.
 *
 * Permite transportar el archivo final y metadata relevante sin
 * acoplarse a una implementación concreta.
 */
final class UploadResult
{
    /**
     * @param string               $path          Ruta o identificador final del archivo aceptado.
     * @param int                  $size          Tamaño del archivo en bytes después de la normalización.
     * @param UploadMetadata       $metadata      Metadata bien tipada del artefacto.
     * @param string|null          $quarantineId  Identificador/ruta del artefacto en cuarentena (si aplica).
     */
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly UploadMetadata $metadata,
        public readonly ?string $quarantineId = null,
    ) {
    }
}
