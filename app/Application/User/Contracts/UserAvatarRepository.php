<?php

declare(strict_types=1);

namespace App\Application\User\Contracts;

use App\Application\Media\Contracts\MediaOwner;
use App\Application\Media\Contracts\MediaProfile;
use App\Application\Media\Contracts\UploadedMedia;
use App\Application\User\DTO\AvatarDeletionResult;
use App\Application\User\DTO\AvatarUpdateResult;

/**
 * Operaciones de avatar desacopladas del modelo concreto.
 */
interface UserAvatarRepository
{
    /**
     * Reemplaza el avatar del usuario y devuelve datos normalizados del resultado.
     *
     * @param MediaOwner $user Usuario que posee el avatar
     * @param UploadedMedia $file Archivo subido por el usuario
     * @param MediaProfile $profile Perfil de configuración para el avatar
     * @param string $uploadUuid Identificador único de la subida
     * @return AvatarUpdateResult Resultado con información sobre la operación de actualización
     */
    public function replaceAvatar(MediaOwner $user, UploadedMedia $file, MediaProfile $profile, string $uploadUuid): AvatarUpdateResult;

    /**
     * Elimina el avatar actual (si existe) y devuelve datos para cleanup.
     *
     * @param MediaOwner $user Usuario del que se eliminará el avatar
     * @return AvatarDeletionResult Resultado con información sobre la operación de eliminación
     */
    public function deleteAvatar(MediaOwner $user): AvatarDeletionResult;
}
