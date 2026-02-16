<?php // Adaptador Eloquent para persistir metadata de uploads no imagen

declare(strict_types=1); // Habilita tipado estricto

namespace App\Infrastructure\Uploads\Core\Repositories; // Namespace del adaptador

use App\Application\Uploads\Contracts\UploadRepositoryInterface; // Contrato de repositorio
use App\Application\Uploads\DTO\UploadResult; // DTO de resultado de upload
use App\Domain\Uploads\UploadProfile; // Perfil de upload
use App\Infrastructure\Models\User; // Modelo de usuario
use App\Infrastructure\Uploads\Core\Models\Upload; // Modelo Upload para persistencia

/**
 * Persiste uploads no imagen en la tabla uploads.
 */
final class EloquentUploadRepository implements UploadRepositoryInterface // Implementa contrato de repositorio
{
    /**
     * Guarda metadata de un upload en la tabla uploads.
     *
     * @param UploadResult $result Resultado del upload
     * @param UploadProfile $profile Perfil usado
     * @param User $actor Usuario que sube
     * @param int|string|null $ownerId Owner opcional
     */
    public function store(UploadResult $result, UploadProfile $profile, User $actor, int|string|null $ownerId = null): void // Persiste metadata
    {
        $ownerValue = $this->normalizeOwnerIdForStorage($ownerId);

        Upload::query()->updateOrCreate( // Inserta o actualiza por PK UUID
            ['id' => $result->id], // Usa id como clave primaria
            [
                'tenant_id' => $result->tenantId, // Tenant propietario del upload
                'owner_type' => $ownerValue !== null ? User::class : null, // Tipo de owner si aplica
                'owner_id' => $ownerValue, // ID del owner si aplica
                'profile_id' => $result->profileId, // Perfil de upload
                'disk' => $result->disk, // Disco de almacenamiento
                'path' => $result->path, // Path relativo
                'mime' => $result->mime, // MIME real
                'size' => $result->size, // Tamaño en bytes
                'checksum' => $result->checksum, // Checksum opcional
                'original_name' => null, // Nombre original no se guarda para no exponer PII
                'visibility' => 'private', // Visibilidad privada por defecto
                'created_by_user_id' => $actor->getKey(), // Usuario que subió
            ]
        ); // Fin updateOrCreate
    }

    private function normalizeOwnerIdForStorage(int|string|null $ownerId): ?string
    {
        if ($ownerId === null) {
            return null;
        }

        $value = is_string($ownerId) ? trim($ownerId) : (string) $ownerId;
        return $value !== '' ? $value : null;
    }
}
