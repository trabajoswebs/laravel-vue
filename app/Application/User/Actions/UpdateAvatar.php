<?php

declare(strict_types=1);

namespace App\Application\User\Actions;

use App\Application\Media\Contracts\MediaUploader;
use App\Application\Media\Contracts\MediaOwner;
use App\Application\Media\Contracts\MediaProfile;
use App\Application\Media\Contracts\UploadedMedia;
use App\Application\Shared\Contracts\LoggerInterface;
use Illuminate\Support\Str;

/**
 * Acción invocable para actualizar el avatar de un usuario.
 *
 * Esta clase encapsula la orquestación ligera para encolar el procesamiento de avatar.
 * La normalización/AV/persistencia se ejecuta en segundo plano vía job.
 */
final class UpdateAvatar
{
    /**
     * Constructor que inyecta las dependencias necesarias.
     *
     * @param MediaProfile         $profile  Perfil específico para el avatar que define las conversiones y la colección.
     */
    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly MediaProfile $profile,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Actualiza el avatar del usuario con el archivo proporcionado.
     *
     * Esta acción:
     * 1. Valida/duplica el archivo en la fase HTTP (vía uploader).
     * 2. Encola el job que procesará AV + normalización + persistencia.
     * 3. Devuelve un ticket de procesamiento para seguimiento en cliente.
     *
     * @param MediaOwner     $user Modelo que posee el avatar.
     * @param UploadedMedia   $file El archivo de imagen subido por el usuario.
     *
     */
    public function __invoke(MediaOwner $user, UploadedMedia $file, ?string $uploadUuid = null)
    {
        $uuid = $uploadUuid ?? (string) Str::uuid();
        $ticket = $this->uploader->upload($user, $file, $this->profile, $uuid);

        $this->logger->info('avatar.upload.enqueued', [
            'user_id' => $user->getKey(),
            'collection' => $this->profile->collection(),
            'upload_uuid' => $uuid,
            'quarantine_id' => $ticket->quarantineId,
            'correlation_id' => $ticket->correlationId,
        ]);

        return $ticket;
    }
}
