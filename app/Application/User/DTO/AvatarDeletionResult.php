<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

/**
 * Resultado normalizado de la eliminación de un avatar.
 */
final class AvatarDeletionResult
{
    /**
     * @param bool        $deleted          Indica si se eliminó un media.
     * @param string|null $mediaId          ID del media eliminado (si aplica).
     * @param array<string,list<array{dir:string,mediaId:string}>> $cleanupArtifacts Rutas a limpiar agrupadas por disco.
     * @param object|null $media            Instancia del media eliminado (para logging/eventos).
     */
    public function __construct(
        public readonly bool $deleted,              // True si se eliminó un avatar, false si no existía
        public readonly ?string $mediaId,          // ID del media eliminado (null si no había avatar)
        public readonly array $cleanupArtifacts,   // Artículos multimedia a limpiar, agrupados por disco
        public readonly ?object $media = null,     // Instancia del media eliminado para logging/eventos
    ) {}

    /**
     * Verifica si existen artefactos que necesitan limpieza.
     *
     * @return bool True si hay artefactos que limpiar, false en caso contrario
     */
    public function hasArtifacts(): bool
    {
        return $this->cleanupArtifacts !== [];
    }
}
