<?php

declare(strict_types=1);

namespace App\Listeners\User;

use App\Events\User\AvatarUpdated;
use App\Jobs\PurgeOldAvatarJob;
use Illuminate\Support\Facades\Log;

/**
 * Listener que se encarga de eliminar el archivo de avatar anterior de forma segura.
 *
 * Este listener responde al evento `AvatarUpdated` y se asegura de que el archivo
 * de avatar anterior (si existía) sea eliminado del sistema de archivos/disco
 * (por ejemplo, S3) y de la base de datos, incluso si por alguna razón
 * Spatie Media Library no lo eliminó automáticamente (aunque normalmente sí lo haría
 * con `singleFile()`).
 *
 * Este listener despacha un Job en la cola `media` para realizar la purga
 * sin bloquear la solicitud HTTP principal y mantiene la idempotencia del flujo.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class PurgeOldAvatar
{
    // Listener ligero: delega el trabajo pesado a un Job dedicado (PurgeOldAvatarJob).
    // Esto evita duplicar lógica en listeners encolados y permite aplicar ShouldBeUnique.

    /**
     * Maneja el evento AvatarUpdated.
     *
     * Verifica si hay un avatar anterior que eliminar, lo busca,
     * aplica controles de seguridad (idempotencia, defensas) y,
     * si es seguro hacerlo, lo elimina.
     *
     * @param  AvatarUpdated  $event  El evento que se está manejando.
     * @return void
     */
    public function handle(AvatarUpdated $event): void
    {
        // Si no hay media antiguo informado en el evento, no hay nada que purgar.
        if (empty($event->oldMediaId)) {
            Log::debug('PurgeOldAvatar: no oldMediaId provided, nothing to do.', [
                'user_id'      => $event->userId, // ID del usuario afectado
                'new_media_id' => $event->newMediaId, // ID del nuevo avatar
            ]);
            return; // Salir sin hacer nada (idempotente)
        }

        PurgeOldAvatarJob::dispatch(
            (int) $event->userId,
            $event->oldMediaId !== null ? (int) $event->oldMediaId : null,
            $event->newMediaId !== null ? (int) $event->newMediaId : null,
        );

        Log::info('PurgeOldAvatar: dispatched purge job.', [
            'user_id'      => $event->userId,
            'old_media_id' => $event->oldMediaId,
            'new_media_id' => $event->newMediaId,
        ]);
    }
}
