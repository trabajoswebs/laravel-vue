<?php // Contrato para persistir metadata de uploads

declare(strict_types=1); // Tipado estricto

namespace App\Application\Uploads\Contracts; // Namespace de contratos de uploads

use App\Application\Uploads\DTO\UploadResult; // DTO de resultado de upload
use App\Domain\Uploads\UploadProfile; // Perfil de upload
use App\Models\User; // Modelo de usuario

/**
 * Permite persistir metadata de uploads no imagen.
 */
interface UploadRepositoryInterface // Contrato de repositorio
{
    /**
     * Guarda metadata de un upload.
     *
     * @param UploadResult $result Resultado del upload
     * @param UploadProfile $profile Perfil usado
     * @param User $actor Usuario que sube
     * @param int|string|null $ownerId Owner opcional
     */
    public function store(UploadResult $result, UploadProfile $profile, User $actor, int|string|null $ownerId = null): void; // Persiste metadata
}
