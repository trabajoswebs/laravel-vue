<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\User\AvatarUpdated;
use App\Models\User;
use App\Services\ImageUploadService;
use App\Support\Media\Profiles\AvatarProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Acción encargada de actualizar el avatar de un usuario.
 *
 * Esta acción encapsula toda la lógica necesaria para procesar
 * la actualización del avatar de un usuario, incluyendo:
 * - Validaciones defensivas del archivo subido.
 * - Manejo de concurrencia mediante transacciones y locks.
 * - Generación de un nombre de archivo único basado en UUID.
 * - Almacenamiento del archivo en la colección 'avatar' del modelo User,
 *   reemplazando el archivo anterior si existe (singleFile).
 * - Cálculo y almacenamiento de un hash SHA1 del contenido para cache busting.
 * - Actualización de la columna 'avatar_version' en la tabla 'users' si existe.
 * - Registro de logs para auditoría.
 * - Disparo de un evento (AvatarUpdated) para notificar sobre el cambio.
 *
 * @author Tu Nombre <tu.email@dominio.com>
 */
class UpdateAvatar
{
    /**
     * Ejecuta la lógica para actualizar el avatar del usuario.
     *
     * - Valida el archivo y ejecuta en transacción con lock pesimista.
     * - Delegado: subida y normalización vía ImageUploadService + AvatarProfile.
     * - Actualiza avatar_version si existe.
     * - Log y evento de dominio AvatarUpdated.
     *
     * @param User $user El modelo de usuario cuyo avatar se actualizará.
     * @param UploadedFile $file El archivo de imagen subido por el cliente.
     * @return Media La instancia del nuevo archivo adjunto (Media) almacenado por Spatie Media Library.
     * @throws InvalidArgumentException Si el archivo subido no es válido o está vacío.
     */
    public function __invoke(User $user, UploadedFile $file): Media
    {
        // Validaciones defensivas (complementan al FormRequest)
        if (!$file->isValid()) {
            throw new InvalidArgumentException('El archivo subido no es válido.');
        }
        if (($file->getSize() ?? 0) <= 0) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }

        $profile    = new AvatarProfile();
        $collection = $profile->collection();

        /** @var Media $newMedia */
        return DB::transaction(function () use ($user, $file, $profile, $collection): Media {
            $locked   = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $oldMedia = $locked->getFirstMedia($collection);

            /** @var ImageUploadService $uploader */
            $uploader = app(ImageUploadService::class);

            // Sube y normaliza según el Profile (singleFile en el Model)
            $media = $uploader->upload($locked, $file, $profile);

            // Asegura estado fresco (paths/custom props)
            $media->refresh();

            // Normaliza versión a string no vacío o null
            $rawVersion = $media->getCustomProperty('version');
            $version = is_scalar($rawVersion) ? trim((string) $rawVersion) : null;
            $version = $version !== '' ? $version : null;

            // Evita introspección de esquema en caliente repetida
            static $hasAvatarVersion = null;
            if ($hasAvatarVersion === null) {
                $hasAvatarVersion = Schema::hasColumn('users', 'avatar_version');
            }
            if ($hasAvatarVersion) {
                // Solo guarda si cambia, para evitar escrituras innecesarias
                $dirty = $locked->avatar_version !== $version;
                if ($dirty) {
                    $locked->avatar_version = $version;
                    $locked->save();
                }
            }

            // Log estructurado con más contexto
            Log::info('Avatar actualizado', [
                'user_id'      => $locked->getKey(),
                'collection'   => $collection,
                'new_media_id' => $media->id,
                'old_media_id' => $oldMedia?->id,
                'mime'         => (string) $media->mime_type,
                'disk'         => (string) $media->disk,
                'version'      => $version,
            ]);

            // Emite evento tras el commit para evitar carreras
            if (class_exists(AvatarUpdated::class)) {
                DB::afterCommit(function () use ($locked, $media, $oldMedia, $version, $collection) {
                    event(new AvatarUpdated(
                        user: $locked,
                        newMedia: $media,
                        oldMedia: $oldMedia,
                        version: $version,
                        collection: $collection,
                        url: $media->getUrl()
                    ));
                });
            }

            return $media;
        });
    }
}
