<?php // Caso de uso para reemplazar un archivo

declare(strict_types=1); // Tipado estricto

namespace App\Application\Uploads\Actions; // Namespace de actions de uploads

use App\Application\Uploads\Contracts\UploadOrchestratorInterface; // Contrato del orquestador
use App\Application\Uploads\DTO\ReplacementResult; // DTO de reemplazo
use App\Domain\Uploads\UploadProfile; // Perfil de upload
use App\Infrastructure\Uploads\Http\Requests\HttpUploadedMedia; // Wrapper de archivo subido
use App\Infrastructure\Models\User; // Modelo de usuario

/**
 * Orquesta el reemplazo de un archivo según perfil.
 */
final class ReplaceFile // Acción invocable de reemplazo
{
    /**
     * @param UploadOrchestratorInterface $orchestrator Orquestador de uploads
     */
    public function __construct(private readonly UploadOrchestratorInterface $orchestrator) // Injerta orquestador
    {
    }

    /**
     * Ejecuta el reemplazo.
     *
     * @param UploadProfile $profile Perfil de upload
     * @param User $actor Usuario autenticado
     * @param HttpUploadedMedia $file Archivo recibido
     * @param int|string|null $ownerId Owner opcional
     * @return ReplacementResult Resultado del reemplazo
     */
    public function __invoke(UploadProfile $profile, User $actor, HttpUploadedMedia $file, int|string|null $ownerId = null): ReplacementResult // Acción invocable
    {
        return $this->orchestrator->replace($profile, $actor, $file, $ownerId); // Delegado al orquestador
    }
}
