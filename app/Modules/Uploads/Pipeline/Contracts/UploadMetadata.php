<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Contracts;

/**
 * Metadata estructurada asociada a un artefacto subido.
 */
final class UploadMetadata
{
    /**
     * @param string      $mime             MIME real del archivo (p.ej. image/webp).
     * @param string|null $extension        Extensión sugerida sin punto (p.ej. webp).
     * @param string|null $hash             Hash calculado (sha256/md5) para deduplicación.
     * @param array|null  $dimensions       Dimensiones normalizadas ['width' => 1200, 'height' => 800].
     * @param string|null $originalFilename Nombre original saneado entregado por el cliente.
     */
    public function __construct(
        public readonly string $mime,
        public readonly ?string $extension = null,
        public readonly ?string $hash = null,
        public readonly ?array $dimensions = null,
        public readonly ?string $originalFilename = null,
    ) {
    }
}
