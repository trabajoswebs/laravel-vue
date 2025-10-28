<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\User\AvatarDeleted;
use App\Jobs\CleanupMediaArtifactsJob;
use App\Models\User;
use App\Support\Media\MediaArtifactCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        private readonly MediaArtifactCollector $artifactCollector,
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
     * @param User $user El modelo de usuario cuyo avatar se eliminará.
     * @return bool true si había avatar y se eliminó, false si no había nada.
     */
    public function __invoke(User $user): bool
    {
        // Inicia una transacción de base de datos para garantizar atomicidad
        return DB::transaction(function () use ($user): bool {
            /** @var User $locked */
            // Aplica un lock pesimista (SELECT ... FOR UPDATE) en el registro del usuario
            // para evitar condiciones de carrera si múltiples solicitudes intentan
            // eliminar el avatar simultáneamente.
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            /** @var Media|null $media */
            // Obtiene el primer archivo adjunto en la colección 'avatar'
            // Si no hay archivo adjunto, retorna false inmediatamente.
            $media = $locked->getFirstMedia('avatar');
            if (! $media) {
                return false; // Nada que borrar - idempotencia
            }

            // Construye payload de cleanup antes de eliminar
            // para poder usarlo en el log posteriormente.
            $mediaId = $media->id;
            $cleanupArtifacts = $this->artifactsForCleanup($locked, $media);

            // Bloque try...catch para manejar posibles errores al eliminar
            // el archivo físico (por ejemplo, en S3).
            try {
                // Elimina el archivo de la base de datos y del sistema de archivos/disco.
                // Spatie Media Library se encarga de eliminar también las conversiones
                // generadas previamente (e.g., thumb, medium, large) y el archivo original
                // en el disco configurado (en este caso, S3).
                $media->delete();
            } catch (\Throwable $e) {
                // Registra un error si falla la eliminación del archivo físico
                Log::error('Error eliminando media (S3/DB)', [
                    'user_id'  => $locked->getKey(), // ID del usuario
                    'media_id' => $mediaId,          // ID del archivo que falló al eliminar
                    'error'    => $e->getMessage(),  // Mensaje de error
                ]);
                // Relanza la excepción para forzar un rollback de la transacción
                throw $e;
            }

            // Si el modelo User tiene una columna 'avatar_version',
            // la actualizamos a null ya que ya no hay avatar.
            // Verificamos su existencia para evitar errores si la columna no está presente.
            if (self::$hasAvatarVersionColumn === null) {
                self::$hasAvatarVersionColumn = Schema::hasColumn('users', 'avatar_version');
            }

            if (self::$hasAvatarVersionColumn) {
                $locked->avatar_version = null; // Limpia el campo
                $locked->save(); // Guarda los cambios en la base de datos
            }

            // Registra un evento de información en los logs
            Log::info('Avatar eliminado', [
                'user_id'  => $locked->getKey(), // ID del usuario
                'media_id' => $mediaId,          // ID del archivo eliminado
            ]);

            // Verifica si la clase del evento AvatarDeleted existe
            // antes de intentar crear y disparar una instancia del evento.
            if (class_exists(AvatarDeleted::class)) {
                // Dispara el evento AvatarDeleted para notificar a otros componentes
                // del sistema sobre la eliminación del avatar.
                // Puede usarse para purgar CDN, métricas, webhooks, etc.
                event(new AvatarDeleted(
                    userId: $locked->getKey(), // ID del usuario
                    mediaId: $mediaId          // ID del archivo eliminado
                ));
            }

            if ($cleanupArtifacts !== []) {
                DB::afterCommit(function () use ($cleanupArtifacts) {
                    CleanupMediaArtifactsJob::dispatch($cleanupArtifacts)
                        ->delay(now()->addSeconds(self::CLEANUP_DELAY_SECONDS));
                });
            }

            // Retorna true para indicar que se eliminó un avatar exitosamente.
            return true;
        });
    }

    /**
     * Prepara el payload de artefactos para cleanup aun si las conversions todavía no existen.
     *
     * @return array<string,list<array{dir:string,mediaId:string}>>
     */
    private function artifactsForCleanup(User $owner, Media $media): array
    {
        $entries = $this->artifactCollector->collectDetailed($owner, $media->collection_name);
        $targetId = (string) $media->getKey();
        $artifacts = [];

        foreach ($entries as $entry) {
            if (
                !isset($entry['media']) ||
                !$entry['media'] instanceof Media ||
                (string) $entry['media']->getKey() !== $targetId ||
                !isset($entry['disks']) ||
                !is_array($entry['disks'])
            ) {
                continue;
            }

            foreach ($entry['disks'] as $disk => $types) {
                if (!is_string($disk) || $disk === '' || !is_array($types)) {
                    continue;
                }

                foreach (['original', 'conversions', 'responsive'] as $type) {
                    $path = $types[$type]['path'] ?? null;
                    if (!is_string($path) || $path === '') {
                        continue;
                    }

                    $artifacts[$disk][] = [
                        'dir'     => $path,
                        'mediaId' => $targetId,
                    ];
                }
            }
        }

        foreach ($artifacts as $disk => $items) {
            $seen = [];
            $deduped = [];

            foreach ($items as $item) {
                if (!isset($item['dir']) || !is_string($item['dir'])) {
                    continue;
                }

                $key = $item['dir'] . '|' . ($item['mediaId'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $deduped[] = $item;
            }

            if ($deduped === []) {
                unset($artifacts[$disk]);
                continue;
            }

            $artifacts[$disk] = $deduped;
        }

        return $artifacts;
    }
}
