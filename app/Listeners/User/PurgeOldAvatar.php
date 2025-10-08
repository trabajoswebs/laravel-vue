<?php

declare(strict_types=1);

namespace App\Listeners\User;

use App\Events\User\AvatarUpdated;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Listener que se encarga de eliminar el archivo de avatar anterior de forma segura.
 *
 * Este listener responde al evento `AvatarUpdated` y se asegura de que el archivo
 * de avatar anterior (si existía) sea eliminado del sistema de archivos/disco
 * (por ejemplo, S3) y de la base de datos, incluso si por alguna razón
 * Spatie Media Library no lo eliminó automáticamente (aunque normalmente sí lo haría
 * con `singleFile()`).
 *
 * Este listener se ejecuta en cola para no bloquear la solicitud HTTP principal
 * y es idempotente, lo que significa que puede ejecutarse múltiples veces
 * sin efectos adversos si el archivo ya fue eliminado.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class PurgeOldAvatar implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue;

    /**
     * Indica si el job debe esperar a que la transacción que lo originó se confirme.
     *
     * Al estar en `true`, el job no se ejecutará hasta que la transacción de la base de datos
     * que originó el evento AvatarUpdated se haya hecho commit. Esto es importante
     * para garantizar que los datos (como la existencia del nuevo avatar y la
     * eliminación del anterior en la base de datos si ML lo hizo) estén consistentes
     * cuando el listener se ejecute.
     *
     * @var bool
     */
    public bool $afterCommit = true;

    /**
     * Número de reintentos en caso de error transitorio.
     *
     * @var int
     */
    public int $tries = 5;

    /**
     * Backoff progresivo entre intentos (en segundos).
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

    /**
     * Especifica la cola en la que se debe ejecutar este job.
     *
     * Usar una cola dedicada como 'media' puede ser útil para priorizar
     * o procesar de forma aislada trabajos relacionados con archivos multimedia.
     *
     * @return string Nombre de la cola.
     */
    public function viaQueue(): string
    {
        return 'media'; // Ejecuta este job en la cola 'media'
    }

    /**
     * Genera un ID único para este job en la cola.
     *
     * Esto evita que se encolen múltiples jobs idénticos para la misma combinación
     * de usuario, media antiguo y media nuevo. Es útil si el evento AvatarUpdated
     * se dispara varias veces rápidamente para el mismo usuario.
     *
     * @return string ID único del job.
     */
    public function uniqueId(): string
    {
        // Obtiene el evento asociado al job cuando se serializa
        /** @var AvatarUpdated $e */
        $e = $this->event ?? null;
        if ($e) {
            // Genera un ID único basado en user_id, old_media_id y new_media_id
            return sprintf('purge:%d:%s:%s', $e->userId, $e->oldMediaId ?? 'null', $e->newMediaId);
        }
        // Fallback: usa el hash del objeto si no se puede acceder al evento
        return spl_object_hash($this);
    }

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

        // Busca el archivo de avatar anterior por su ID en la base de datos.
        /** @var Media|null $oldMedia */
        $oldMedia = Media::find($event->oldMediaId);

        // Si no se encuentra el archivo (ya fue borrado por ML o por otro proceso),
        // somos idempotentes: no hacemos nada.
        if (! $oldMedia) {
            Log::debug('PurgeOldAvatar: old media not found (already deleted).', [
                'user_id'      => $event->userId, // ID del usuario afectado
                'old_media_id' => $event->oldMediaId, // ID del avatar antiguo (ya no existe)
            ]);
            return; // Salir sin hacer nada (idempotente)
        }

        // Defensa: si por error el avatar antiguo es el mismo que el nuevo,
        // no borramos el nuevo avatar accidentalmente.
        if ($oldMedia->id === $event->newMediaId) {
            Log::warning('PurgeOldAvatar: old media equals new media, skipping.', [
                'user_id'  => $event->userId, // ID del usuario afectado
                'media_id' => $oldMedia->id,  // ID del avatar (que coincide con el nuevo)
            ]);
            return; // Salir sin hacer nada (defensiva)
        }

        // Defensa: asegura que el archivo que se va a borrar pertenece al mismo usuario
        // del evento. Evita borrar archivos de otros usuarios por errores de datos.
        if ((int) $oldMedia->model_id !== (int) $event->userId) {
            Log::warning('PurgeOldAvatar: media model_id does not match userId, skipping.', [
                'event_user_id'  => $event->userId,    // ID del usuario del evento
                'media_model_id' => $oldMedia->model_id, // ID del modelo propietario del archivo
                'old_media_id'   => $oldMedia->id,    // ID del avatar antiguo
            ]);
            return; // Salir sin hacer nada (defensiva)
        }

        // Bloque try...catch para manejar posibles errores al eliminar el archivo físico (S3, etc.)
        try {
            // Elimina el archivo de la base de datos y del sistema de archivos/disco.
            // Spatie Media Library se encarga de eliminar también las conversiones
            // generadas previamente (e.g., thumb, medium, large) y el archivo original
            // en el disco configurado (en este caso, S3).
            $oldMedia->delete();

            // Registra un evento de información en los logs
            Log::info('PurgeOldAvatar: old media deleted successfully.', [
                'user_id'      => $event->userId, // ID del usuario afectado
                'old_media_id' => $event->oldMediaId, // ID del avatar eliminado
            ]);
        } catch (\Throwable $e) {
            // Registra un error si falla la eliminación del archivo físico
            Log::error('PurgeOldAvatar: failed to delete old media.', [
                'user_id'      => $event->userId, // ID del usuario afectado
                'old_media_id' => $event->oldMediaId, // ID del avatar que falló al eliminar
                'error'        => $e->getMessage(), // Mensaje de error
            ]);
            // Relanza la excepción para que el job entre en reintentos según $tries/$backoff
            throw $e;
        }
    }
}