<?php

declare(strict_types=1);

namespace App\Application\User\Contracts;

use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;

/**
 * Acceso y persistencia de usuarios desde la capa Application.
 */
interface UserRepository
{
    /**
     * Obtiene el usuario con lock pesimista o lanza si no existe.
     *
     * @param int|string $id Identificador del usuario
     * @return MediaOwner Usuario encontrado con lock aplicado
     */
    public function lockAndFindById(int|string $id): MediaOwner;

    /**
     * Persiste los cambios del usuario.
     *
     * @param MediaOwner $user Usuario con cambios a persistir
     */
    public function save(MediaOwner $user): void;
}
