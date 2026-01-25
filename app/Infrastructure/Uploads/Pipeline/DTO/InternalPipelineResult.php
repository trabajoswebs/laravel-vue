<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\DTO;

use App\Infrastructure\Uploads\Pipeline\Contracts\UploadMetadata;

/**
 * DTO mínimo para resultados de pipelines de subida.
 *
 * Permite transportar el archivo final y metadata relevante sin
 * acoplarse a una implementación concreta.
 */
final class InternalPipelineResult
{
    /**
     * @param string               $path          Ruta o identificador final del archivo aceptado.
     * @param int                  $size          Tamaño del archivo en bytes después de la normalización.
     * @param UploadMetadata       $metadata      Metadata bien tipada del artefacto.
     * @param \App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineToken|null $quarantineId  Token del artefacto en cuarentena (si aplica).
     */
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly UploadMetadata $metadata,
        public readonly ?\App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineToken $quarantineId = null,
    ) {
    }
}
