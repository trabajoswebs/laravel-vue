<?php

declare(strict_types=1);

namespace App\Application\User\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de dominio disparado cuando se elimina el avatar de un usuario.
 *
 * Útil para:
 * - Purgar caché/CDN
 * - Métricas/telemetría
 * - Notificar a servicios externos (webhooks)
 */
class AvatarDeleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * ID del usuario afectado.
     */
    public int $userId;

    /**
     * ID del registro Media eliminado (Spatie).
     */
    public int $mediaId;

    /**
     * @param  int  $userId   Identificador del usuario propietario del avatar.
     * @param  int  $mediaId  Identificador del Media eliminado (Spatie Media Library).
     */
    public function __construct(int $userId, int $mediaId)
    {
        $this->userId  = $userId;
        $this->mediaId = $mediaId;
    }
}
