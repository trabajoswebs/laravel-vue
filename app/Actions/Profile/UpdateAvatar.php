<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Events\User\AvatarUpdated;
use App\Models\User;
use App\Services\ImagePipeline;
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
     * Realiza validaciones iniciales del archivo subido, inicia una transacción
     * de base de datos, aplica un lock pesimista al usuario para evitar
     * condiciones de carrera, y procede a adjuntar el nuevo archivo como avatar.
     * También registra logs y dispara un evento al finalizar exitosamente.
     *
     * @param User $user El modelo de usuario cuyo avatar se actualizará.
     * @param UploadedFile $file El archivo de imagen subido por el cliente.
     * @return Media La instancia del nuevo archivo adjunto (Media) almacenado por Spatie Media Library.
     * @throws InvalidArgumentException Si el archivo subido no es válido o está vacío.
     */
    public function __invoke(User $user, UploadedFile $file): Media
    {
        // Validaciones defensivas
        // Verifica si el archivo subido pasó la validación de PHP (no está corrupto, etc.)
        if (!$file->isValid()) {
            throw new InvalidArgumentException('El archivo subido no es válido.');
        }

        // Verifica que el archivo no esté vacío (tamaño mayor a 0 bytes)
        // El operador ?? 0 maneja el caso donde getSize() podría devolver null.
        if (($file->getSize() ?? 0) <= 0) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }

        // Inicia una transacción de base de datos para garantizar atomicidad
        return DB::transaction(function () use ($user, $file): Media {
            // Aplica un lock pesimista (SELECT ... FOR UPDATE) en el registro del usuario
            // para evitar condiciones de carrera si múltiples solicitudes intentan
            // actualizar el avatar simultáneamente.
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            // Guarda una referencia al archivo de avatar anterior (si existe)
            // para posibles usos posteriores (logging, eventos, limpieza).
            $oldMedia = $locked->getFirstMedia('avatar');

            /** @var ImagePipeline $pipeline */
            $pipeline = app(ImagePipeline::class);
            $res = $pipeline->process($file); // ← genera un archivo temporal normalizado
            
            try {
                // 2) Adjuntar a Media Library usando el TEMPORAL del pipeline (NO el UploadedFile original)
                $targetFileName = 'avatar-'.$res->contentHash.'.'.$res->extension;

                // Adjunta el archivo subido al modelo de usuario en la colección 'avatar'.
                // Utiliza el nombre de archivo generado y agrega propiedades personalizadas.
                $newMedia = $locked
                    ->addMedia($res->path)// Adjunta el archivo subido
                    ->usingFileName($targetFileName) // Usa el nombre de archivo único
                    ->withCustomProperties([ // Agrega propiedades personalizadas
                        'version'     => $res->contentHash, // Hash para cache busting
                        'uploaded_at' => now()->toIso8601String(),// Fecha de subida
                        'mime_type'   => $res->mime,// Tipo MIME real del archivo
                        'width'       => $res->width,
                        'height'      => $res->height,
                    ])
                    ->toMediaCollection('avatar'); // A la colección 'avatar'
         

                // Verifica si la columna 'avatar_version' existe en la tabla 'users'
                // antes de intentar actualizarla. Esto evita errores si la migración
                // no ha sido ejecutada aún.
                if (Schema::hasColumn('users', 'avatar_version')) {
                    // Actualiza el campo avatar_version en el modelo de usuario
                    // con el hash calculado del nuevo archivo.
                    $locked->avatar_version = $res->contentHash;
                    $locked->save(); // Guarda los cambios en la base de datos
                }

                // Registra un evento de información en los logs
                Log::info('Avatar actualizado', [
                    'user_id'      => $locked->getKey(), // ID del usuario
                    'new_media_id' => $newMedia->id,     // ID del nuevo archivo adjunto
                    'old_media_id' => $oldMedia?->id,    // ID del archivo anterior (si existía)
                    'mime'         => $res->mime,             // Tipo MIME del archivo subido
                    'version'      => $res->contentHash,      // Hash del archivo (versión)
                ]);

                // Verifica si la clase del evento AvatarUpdated existe
                // antes de intentar crear y disparar una instancia del evento.
                if (class_exists(AvatarUpdated::class)) {
                    // Dispara el evento AvatarUpdated para notificar a otros componentes
                    // del sistema sobre la actualización del avatar.
                    event(new AvatarUpdated(
                        userId: $locked->getKey(),    // ID del usuario
                        oldMediaId: $oldMedia?->id,   // ID del archivo anterior (o null)
                        newMediaId: $newMedia->id,    // ID del nuevo archivo
                        version: $res->contentHash         // Hash del nuevo archivo
                    ));
                }
                // Retorna la instancia del nuevo archivo adjunto (Media)

                return $newMedia;

            } finally {
                // 5) Limpieza del temporal generado por el pipeline (garantizada)
                $res->cleanup();
            }
        });
    }
}
