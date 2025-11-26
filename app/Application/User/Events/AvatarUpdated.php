<?php

declare(strict_types=1);

namespace App\Application\User\Events;

use App\Domain\User\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
    use Dispatchable;        // Permite disparar el evento con event(new AvatarUpdated(...))
    use InteractsWithSockets; // Soporte para broadcasting por websockets (si se implementa en el futuro)
    use SerializesModels;    // Serializa modelos de forma segura para colas (solo guarda IDs)

    /**
     * Usuario que actualizó su avatar.
     *
     * @var User
     */
    public readonly User $user;

    /**
     * Nuevo registro Media asociado al avatar.
     *
     * @var Media
     */
    public readonly Media $newMedia;

    /**
     * Media previo si existía (null si es el primer avatar).
     *
     * @var Media|null
     */
    public readonly ?Media $oldMedia;

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
     * @param User       $user        Usuario afectado.
     * @param Media      $newMedia    Media recién adjuntado.
     * @param Media|null $oldMedia    Media anterior (o null).
     * @param string|null $version    Hash/versión de cache.
     * @param string      $collection Nombre de colección (por defecto "avatar").
     * @param string|null $url        URL absoluta sugerida del avatar.
     */
    public function __construct(
        User $user,                  // Usuario que actualizó su avatar
        Media $newMedia,             // Nuevo archivo adjunto
        ?Media $oldMedia = null,     // Archivo anterior (si existía)
        ?string $version = null,     // Versión/hash para cache busting
        string $collection = 'avatar', // Colección ML usada (por defecto 'avatar')
        ?string $url = null          // URL pública del avatar (opcional)
    ) {
        $this->user = $user;               // Asigna el usuario afectado
        $this->newMedia = $newMedia;       // Asigna el nuevo archivo adjunto
        $this->oldMedia = $oldMedia;       // Asigna el archivo anterior (o null)
        $this->version = $version;         // Asigna la versión/hash
        $this->collection = $collection;   // Asigna el nombre de la colección
        $this->url = $url;                 // Asigna la URL sugerida del avatar
        $this->replaced = $oldMedia !== null; // Calcula si hubo reemplazo
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
        $userId = $this->user->getKey();
        $newMediaId = $this->newMedia->getKey();
        $oldMediaId = $this->oldMedia?->getKey();

        // Convierte las claves a enteros si son numéricas, de lo contrario las deja como string
        // Esto es útil si se espera que los IDs sean enteros en los logs o sistemas externos
        return [
            'user_id'      => is_numeric($userId) ? (int) $userId : $userId, // ID del usuario afectado
            'new_media_id' => is_numeric($newMediaId) ? (int) $newMediaId : $newMediaId, // ID del nuevo archivo adjunto
            'old_media_id' => $oldMediaId === null ? null : (is_numeric($oldMediaId) ? (int) $oldMediaId : $oldMediaId), // ID del archivo anterior (o null)
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
