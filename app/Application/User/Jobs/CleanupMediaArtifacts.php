<?php

declare(strict_types=1);

namespace App\Application\User\Jobs;

/**
 * Mensaje de aplicación para limpiar artefactos de media.
 *
 * Este objeto transporta la intención (payload y preservaciones) sin depender
 * de Laravel ni de la capa de infraestructura. La implementación concreta que
 * hace el trabajo vive en Infrastructure y se adapta desde este mensaje.
 *
 * @param array<string, list<string|array{dir:string,mediaId?:string|null}>> $artifacts
 * @param array<int|string> $preserveMediaIds
 */
final class CleanupMediaArtifacts
{
    /**
     * @param array<string, list<string|array{dir:string,mediaId?:string|null}>> $artifacts
     * @param array<int|string> $preserveMediaIds
     */
    public function __construct(
        public readonly array $artifacts,
        public readonly array $preserveMediaIds = [],
    ) {}
}
