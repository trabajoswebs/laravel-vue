<?php

declare(strict_types=1);

namespace App\Application\User\Events;

/**
 * Evento de dominio disparado cuando un usuario actualiza su avatar.
 *
 * Este evento se emite cuando una acción de actualización de avatar
 * se completa exitosamente. Transporta información relevante sobre
 * el cambio para que otros componentes del sistema (listeners, jobs, etc.)
 * puedan reaccionar de forma adecuada.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
final class AvatarUpdated
{
    /**
     * Usuario que actualizó su avatar.
     *
     * @var int|string
     */
    public readonly int|string $userId;

    /**
     * Nuevo registro Media asociado al avatar.
     *
     * @var int|string
     */
    public readonly int|string $newMediaId;

    /**
     * Media previo si existía (null si es el primer avatar).
     *
     * @var int|string|null
     */
    public readonly int|string|null $oldMediaId;

    /**
     * Hash/versión calculado para cache-busting (puede vivir en custom properties o columna user).
     *
     * @var string|null
     */
    public readonly ?string $version;

    /**
     * Nombre de la colección usada (por defecto "avatar").
     *
     * @var string
     */
    public readonly string $collection;

    /**
     * Indica si hubo reemplazo (true) o era el primer avatar (false).
     *
     * @var bool
     */
    public readonly bool $replaced;

    /**
     * URL absoluta sugerida para el nuevo avatar (opcional, útil para listeners/colas).
     *
     * @var string|null
     */
    public readonly ?string $url;

    /**
     * Crea un nuevo evento AvatarUpdated.
     *
     * @param int|string  $userId      ID del usuario afectado.
     * @param int|string  $newMediaId  ID del media recién adjuntado.
     * @param int|string|null $oldMediaId ID del media anterior (o null).
     * @param string|null $version    Hash/versión de cache.
     * @param string      $collection Nombre de colección (por defecto "avatar").
     * @param string|null $url        URL absoluta sugerida del avatar.
     */
    public function __construct(
        int|string $userId,                  // Usuario que actualizó su avatar
        int|string $newMediaId,             // Nuevo archivo adjunto
        int|string|null $oldMediaId = null,     // Archivo anterior (si existía)
        ?string $version = null,     // Versión/hash para cache busting
        string $collection = 'avatar', // Colección ML usada (por defecto 'avatar')
        ?string $url = null          // URL pública del avatar (opcional)
    ) {
        $this->userId = $userId;               // ID del usuario afectado
        $this->newMediaId = $newMediaId;       // ID del nuevo archivo adjunto
        $this->oldMediaId = $oldMediaId;       // ID del archivo anterior (o null)
        $this->version = $version;         // Asigna la versión/hash
        $this->collection = $collection;   // Asigna el nombre de la colección
        $this->url = $url;                 // Asigna la URL sugerida del avatar
        $this->replaced = $oldMediaId !== null; // Calcula si hubo reemplazo
    }

    /**
     * Representación array para logs/telemetría.
     *
     * Este método permite convertir el evento en un array asociativo
     * estructurado, útil para registrar eventos en logs estructurados
     * o enviarlos a sistemas de telemetría.
     *
     * @return array<string,mixed> Array con los datos relevantes del evento.
     */
    public function toArray(): array
    {
        // Obtiene las claves primarias de los modelos
        return [
            'user_id'      => $this->userId, // ID del usuario afectado
            'new_media_id' => $this->newMediaId, // ID del nuevo archivo adjunto
            'old_media_id' => $this->oldMediaId, // ID del archivo anterior (o null)
            'collection'   => $this->collection, // Nombre de la colección ML
            'version'      => $this->version,   // Hash/versión del avatar
            'replaced'     => $this->replaced,  // Indica si hubo reemplazo
            'url'          => $this->url,       // URL sugerida del avatar
        ];
    }

    /**
     * Nombre estático del evento (útil para trazas/telemetría).
     *
     * Proporciona un nombre canónico para el evento, útil para
     * identificarlo en listeners genéricos o en sistemas de análisis.
     *
     * @return string Nombre del evento en formato "dominio.objeto.accion".
     */
    public static function name(): string
    {
        return 'user.avatar.updated'; // Nombre canónico del evento
    }
}
