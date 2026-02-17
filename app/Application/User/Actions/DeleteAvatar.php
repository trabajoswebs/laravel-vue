<?php

declare(strict_types=1);

namespace App\Application\User\Actions;

use App\Application\User\Contracts\UserAvatarRepository;
use App\Application\User\Contracts\UserRepository;
use App\Application\User\Events\AvatarDeleted;
use App\Application\User\Jobs\CleanupMediaArtifacts;
use App\Support\Contracts\AsyncJobDispatcherInterface;
use App\Support\Contracts\ClockInterface;
use App\Support\Contracts\EventBusInterface;
use App\Support\Contracts\LoggerInterface;
use App\Support\Contracts\TransactionManagerInterface;
use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;
use Illuminate\Support\Facades\Schema;

/**
 * Acción encargada de eliminar el avatar de un usuario.
 *
 * Esta acción encapsula la lógica necesaria para eliminar el archivo
 * asociado a la colección 'avatar' del modelo User.
 * Utiliza una transacción de base de datos y un lock pesimista
 * para garantizar la atomicidad y evitar condiciones de carrera.
 * Es idempotente: si no hay avatar, no hace nada y retorna false.
 * Incluye manejo de errores robusto para operaciones de I/O (como eliminar de S3)
 * y dispara un evento (AvatarDeleted) para notificar sobre la eliminación.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class DeleteAvatar
{
    private const CLEANUP_DELAY_SECONDS = 30;

    private static ?bool $hasAvatarVersionColumn = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserAvatarRepository $avatars,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly TransactionManagerInterface $transactions,
        private readonly EventBusInterface $events,
        private readonly AsyncJobDispatcherInterface $jobs,
    ) {}

    /**
     * Elimina el avatar actual (colección 'avatar'). Idempotente.
     *
     * Inicia una transacción de base de datos, aplica un lock pesimista
     * al usuario, obtiene el archivo de avatar actual, lo elimina
     * (incluyendo el archivo físico en S3 y registros en DB),
     * limpia el campo avatar_version si existe, registra logs
     * y dispara un evento (AvatarDeleted) al finalizar exitosamente.
     *
     * @param MediaOwner $user Modelo que posee el avatar.
     * @return bool true si había avatar y se eliminó, false si no había nada.
     */
    public function __invoke(MediaOwner $user): bool
    {
        // Inicia una transacción de base de datos para garantizar atomicidad
        return $this->transactions->transactional(function () use ($user): bool {
            $locked = $this->users->lockAndFindById((string) $user->getKey());

            $result = $this->avatars->deleteAvatar($locked);
            if (!$result->deleted || $result->mediaId === null) {
                return false; // Nada que borrar - idempotencia
            }

            // Si el modelo User tiene una columna 'avatar_version',
            // la actualizamos a null ya que ya no hay avatar.
            // Verificamos su existencia para evitar errores si la columna no está presente.
            if (self::$hasAvatarVersionColumn === null) {
                self::$hasAvatarVersionColumn = Schema::hasColumn('users', 'avatar_version');
            }

            if (self::$hasAvatarVersionColumn) {
                $locked->avatar_version = null; // Limpia el campo
                $this->users->save($locked); // Guarda los cambios en la base de datos
            }

            // Registra un evento de información en los logs
            $this->logger->info('Avatar eliminado', [
                'user_id'  => $locked->getKey(), // ID del usuario
                'media_id' => $result->mediaId,          // ID del archivo eliminado
            ]);

            // Verifica si la clase del evento AvatarDeleted existe
            // antes de intentar crear y disparar una instancia del evento.
            if (class_exists(AvatarDeleted::class)) {
                $this->events->dispatch(new AvatarDeleted(
                    userId: $locked->getKey(), // ID del usuario
                    mediaId: (int) $result->mediaId          // ID del archivo eliminado
                ));
            }

            if ($result->hasArtifacts()) {
                $this->transactions->afterCommit(function () use ($result) {
                    $delay = $this->clock->now()->addSeconds(self::CLEANUP_DELAY_SECONDS);
                    $this->jobs->dispatch(
                        new CleanupMediaArtifacts($result->cleanupArtifacts),
                        $delay
                    );
                });
            }

            // Retorna true para indicar que se eliminó un avatar exitosamente.
            return true;
        });
    }
}
