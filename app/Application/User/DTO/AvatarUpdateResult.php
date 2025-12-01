<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

/**
 * Resultado normalizado de un reemplazo de avatar.
 */
final class AvatarUpdateResult
{
    /**
     * @param object      $newMedia   Media recién creado.
     * @param object|null $oldMedia   Media previo (si existía).
     * @param string|null $version    Versión/hash del avatar.
     * @param string      $collection Colección usada (p.ej., 'avatar').
     * @param string|null $disk       Disco configurado.
     * @param array<string,mixed> $headers Headers asociados al media.
     * @param string      $uploadUuid Identificador idempotente.
     * @param string|null $url        URL pública (si existente).
     * @param string      $mediaId    ID del nuevo media.
     * @param string|null $oldMediaId ID del media reemplazado.
     */
    public function __construct(
        public readonly object $newMedia,        // Instancia del nuevo media creado
        public readonly ?object $oldMedia,       // Instancia del media anterior (null si no existía)
        public readonly ?string $version,        // Versión/hash única del avatar
        public readonly string $collection,      // Nombre de la colección usada (ej: 'avatar')
        public readonly ?string $disk,           // Nombre del disco de almacenamiento
        public readonly array $headers,          // Cabeceras HTTP asociadas al media
        public readonly string $uploadUuid,      // UUID único de la operación de subida
        public readonly ?string $url,            // URL pública del avatar (null si no está disponible)
        public readonly string $mediaId,         // ID del nuevo media
        public readonly ?string $oldMediaId,     // ID del media reemplazado (null si no existía)
    ) {}
}
