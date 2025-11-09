<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\User\AvatarUpdated;
use App\Models\User;
use App\Support\Media\Profiles\AvatarProfile;
use App\Support\Media\Services\MediaReplacementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Acción invocable para actualizar el avatar de un usuario.
 *
 * Esta clase encapsula toda la lógica necesaria para reemplazar el avatar de un usuario,
 * incluyendo la validación, procesamiento, conversión, registro de logs y emisión de eventos.
 * Utiliza una transacción de base de datos para asegurar la consistencia de los datos.
 */
final class UpdateAvatar
{
    /**
     * Constructor que inyecta las dependencias necesarias.
     *
     * @param MediaReplacementService $replacement Servicio encargado del reemplazo y conversión de medios.
     * @param AvatarProfile           $profile     Perfil específico para el avatar que define las conversiones y la colección.
     */
    public function __construct(
        private readonly MediaReplacementService $replacement,
        private readonly AvatarProfile $profile,
    ) {}

    /**
     * Actualiza el avatar del usuario con el archivo proporcionado.
     *
     * Esta acción:
     * 1. Bloquea la fila del usuario para evitar concurrencia.
     * 2. Obtiene el medio actual (avatar anterior).
     * 3. Reemplaza el avatar con el nuevo archivo.
     * 4. Actualiza el campo de versión del usuario si aplica.
     * 5. Registra un evento de log de la operación.
     * 6. Dispara un evento `AvatarUpdated` *después* del commit de la transacción.
     *
     * @param User           $user El modelo de usuario cuyo avatar se va a actualizar.
     * @param UploadedFile   $file El archivo de imagen subido por el usuario.
     *
     * @return Media El modelo de medio recién creado y procesado que representa el nuevo avatar.
     */
    public function __invoke(User $user, UploadedFile $file, ?string $uploadUuid = null): Media
    {
        $collection = $this->profile->collection();
        $disk = $this->profile->disk();
        $uuid = $uploadUuid ?? (string) Str::uuid();
        $remoteDisk = $this->isRemoteDisk($disk);

        return DB::transaction(function () use ($user, $file, $collection, $uuid, $disk, $remoteDisk): Media {
            // Bloquea la fila del usuario para evitar operaciones concurrentes.
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            // Obtiene el avatar anterior (si existe).
            $oldMedia = $locked->getFirstMedia($collection);

            // Reemplaza el avatar y obtiene el resultado (nuevo medio y snapshot).
            $result = $this->replacement->replaceWithSnapshot($locked, $file, $this->profile);
            $media = tap($result->media)->refresh(); // Refresca el modelo para asegurar consistencia.

            // Persiste identificador idempotente.
            $media->setCustomProperty('upload_uuid', $uuid);
            $media->save();

            // Intenta obtener la versión del medio desde una propiedad personalizada.
            $version = rescue(
                fn () => filled($value = $media->getCustomProperty('version')) ? (string) $value : null,
                null
            );

            // Si el modelo de usuario define un campo de versión para esta colección, lo actualiza.
            if (method_exists($locked, 'getMediaVersionColumn') && ($column = $locked->getMediaVersionColumn($collection))) {
                $locked->{$column} = $version;
                $locked->save();
            }

            // Registra un log de la operación de actualización del avatar.
            $headers = (array) $media->getCustomProperty('headers', []);
            $acl = strtolower((string) ($headers['ACL'] ?? ''));
            if ($acl !== '' && $acl !== 'private') {
                Log::warning('avatar.headers.acl_unexpected', [
                    'user_id' => $locked->getKey(),
                    'media_id' => $media->id,
                    'expected' => 'private',
                    'received' => $headers['ACL'] ?? null,
                ]);
            }

            if (!isset($headers['ContentType']) || !is_string($headers['ContentType']) || $headers['ContentType'] === '') {
                Log::warning('avatar.headers.content_type_missing', [
                    'user_id' => $locked->getKey(),
                    'media_id' => $media->id,
                ]);
            }

            Log::info('avatar.updated', [
                'user_id'      => $locked->getKey(),
                'collection'   => $collection,
                'media_id'     => $media->id,
                'replaced_id'  => $oldMedia?->id,
                'version'      => $version,
                'upload_uuid'  => $uuid,
                'disk'         => $disk,
                'headers_acl'  => $headers['ACL'] ?? null,
                'content_type' => $headers['ContentType'] ?? null,
            ]);

            if ($remoteDisk) {
                Log::notice('avatar.remote_disk_detected', [
                    'user_id'    => $locked->getKey(),
                    'media_id'   => $media->id,
                    'disk'       => $disk,
                    'upload_uuid'=> $uuid,
                ]);
            }

            // Dispara el evento AvatarUpdated *después* de que la transacción se haya confirmado.
            DB::afterCommit(function () use ($locked, $media, $oldMedia, $collection, $version) {
                if (!class_exists(AvatarUpdated::class)) {
                    return; // Evita errores si el evento no está definido (por ejemplo, en tests).
                }

                event(new AvatarUpdated(
                    user: $locked,
                    newMedia: $media,
                    oldMedia: $oldMedia,
                    version: $version,
                    collection: $collection,
                    url: $media->getUrl()
                ));
            });

            return $media;
        });
    }

    private function isRemoteDisk(?string $disk): bool
    {
        if ($disk === null || $disk === '') {
            return false;
        }

        $driver = config("filesystems.disks.{$disk}.driver");
        if (!is_string($driver) || $driver === '') {
            return false;
        }

        $driver = strtolower($driver);

        return str_contains($driver, 's3')
            || in_array($driver, ['ftp', 'sftp', 's3', 's3-compatible'], true);
    }
}
