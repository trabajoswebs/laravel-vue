<?php // Contrato para orquestador de uploads

declare(strict_types=1); // Tipado estricto

namespace App\Application\Uploads\Contracts; // Namespace de contratos de uploads

use App\Application\Uploads\DTO\ReplacementResult; // DTO de reemplazo
use App\Application\Uploads\DTO\UploadResult; // DTO de upload
use App\Domain\Uploads\UploadProfile; // Perfil de upload
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia; // Wrapper de archivo subido
use App\Infrastructure\Models\User; // Modelo de usuario para owner/actor

/**
 * Define operaciones de subida y reemplazo.
 */
interface UploadOrchestratorInterface // Contrato de orquestador
{
    /**
     * Sube un archivo según el perfil dado.
     *
     * @param UploadProfile $profile Perfil de upload
     * @param User $actor Usuario que realiza la acción
     * @param HttpUploadedMedia $file Archivo subido
     * @param int|string|null $ownerId Owner opcional
     * @return UploadResult Resultado del upload
     */
    public function upload(UploadProfile $profile, User $actor, HttpUploadedMedia $file, int|string|null $ownerId = null): UploadResult; // Ejecuta upload nuevo

    /**
     * Reemplaza un archivo existente según el perfil.
     *
     * @param UploadProfile $profile Perfil de upload
     * @param User $actor Usuario que realiza la acción
     * @param HttpUploadedMedia $file Archivo subido
     * @param int|string|null $ownerId Owner opcional
     * @return ReplacementResult Resultado del reemplazo
     */
    public function replace(UploadProfile $profile, User $actor, HttpUploadedMedia $file, int|string|null $ownerId = null): ReplacementResult; // Ejecuta reemplazo de archivo
}
