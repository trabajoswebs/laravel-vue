<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Adapters;

use App\Application\User\Contracts\UserRepository;
use App\Infrastructure\Uploads\Core\Contracts\MediaOwner;
use App\Infrastructure\Models\User;

final class EloquentUserRepository implements UserRepository
{
    /**
     * Busca un usuario por ID con lock pesimista para evitar concurrencia.
     *
     * @param int|string $id ID del usuario a buscar
     * @return MediaOwner Usuario encontrado con lock aplicado
     */
    public function lockAndFindById(int|string $id): MediaOwner
    {
        return User::query()->lockForUpdate()->findOrFail($id);
    }

    /**
     * Guarda los cambios de un usuario en la base de datos.
     *
     * @param MediaOwner $user Usuario con cambios a persistir
     */
    public function save(MediaOwner $user): void
    {
        if ($user instanceof User) {
            $user->save();
            return;
        }

        if (method_exists($user, 'save')) {
            $user->save();
        }
    }
}
