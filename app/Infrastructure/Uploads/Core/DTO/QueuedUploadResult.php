<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\DTO;

/**
 * Resultado mínimo de una subida encolada.
 */
final class QueuedUploadResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $correlationId,
        public readonly ?string $quarantineId = null,
        public readonly string|int|null $ownerId = null,
        public readonly ?string $profile = null,
    ) {
    }
}
