<?php

declare(strict_types=1);

namespace App\Application\Media\DTO;

/**
 * Resultado ligero de una subida encolada.
 *
 * Representa el ticket que devuelve la fase HTTP antes de que
 * el pipeline de procesamiento se ejecute en segundo plano.
 */
final class QueuedUploadResult
{
    /**
     * @param string $status Estado actual del upload (ej: processing)
     * @param string $correlationId Identificador de correlación para trazabilidad
     * @param string $quarantineId Identificador opaco del artefacto en cuarentena
     * @param string|int $ownerId Identificador del propietario
     * @param string $profile Nombre de la colección/perfil solicitado
     */
    public function __construct(
        public readonly string $status,
        public readonly string $correlationId,
        public readonly string $quarantineId,
        public readonly string|int $ownerId,
        public readonly string $profile,
    ) {
    }
}
