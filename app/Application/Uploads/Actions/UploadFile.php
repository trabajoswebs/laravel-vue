<?php // Caso de uso para subir un archivo

declare(strict_types=1); // Tipado estricto

namespace App\Application\Uploads\Actions; // Namespace de actions de uploads

use App\Application\Uploads\Contracts\UploadOrchestratorInterface; // Contrato del orquestador
use App\Application\Uploads\DTO\UploadResult; // DTO de resultado
use App\Domain\Uploads\UploadProfile; // Perfil de upload
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia; // Wrapper de archivo subido
use App\Infrastructure\Models\User; // Modelo de usuario

/**
 * Orquesta un upload nuevo usando un perfil.
 */
final class UploadFile // Action simple de upload
{
    /**
     * @param UploadOrchestratorInterface $orchestrator Orquestador de uploads
     */
    public function __construct(private readonly UploadOrchestratorInterface $orchestrator) // Injerta orquestador
    {
    }

    /**
     * Ejecuta el upload.
     *
     * @param UploadProfile $profile Perfil de upload
     * @param User $actor Usuario autenticado que sube
     * @param HttpUploadedMedia $file Archivo recibido
     * @param int|string|null $ownerId Owner opcional
     * @return UploadResult Resultado del upload
     */
    public function __invoke(UploadProfile $profile, User $actor, HttpUploadedMedia $file, int|string|null $ownerId = null): UploadResult // Acción invocable
    {
        return $this->orchestrator->upload($profile, $actor, $file, $ownerId); // Delegado al orquestador
    }
}
