<?php

declare(strict_types=1);

namespace App\Application\User\Actions;

use App\Support\Contracts\LoggerInterface; // Logger para auditoría
use App\Support\Contracts\TenantContextInterface; // Contexto de tenant
use App\Application\Uploads\Actions\ReplaceFile; // Caso de uso de reemplazo
use App\Application\Uploads\DTO\ReplacementResult; // DTO de reemplazo
use App\Domain\Uploads\UploadProfileId; // VO de perfil
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry; // Registro de perfiles
use App\Modules\Uploads\Requests\HttpUploadedMedia; // Adaptador de archivo HTTP
use App\Models\User; // Modelo User que actúa como owner
use App\Infrastructure\Uploads\Pipeline\Jobs\ProcessLatestAvatar; // Job coalescedor de avatar
use Illuminate\Support\Str; // Helper para UUID

/**
 * Acción invocable para actualizar el avatar de un usuario.
 *
 * Esta clase encapsula la orquestación con ReplaceFile + perfiles de upload.
 */
final class UpdateAvatar
{
    /**
     * Constructor que inyecta las dependencias necesarias.
     *
     * @param ReplaceFile $replace Caso de uso de reemplazo de archivo
     * @param UploadProfileRegistry $profiles Registro de perfiles de upload
     * @param TenantContextInterface $tenantContext Contexto para tenant_id
     * @param LoggerInterface $logger Logger para trazabilidad
     */
    public function __construct(
        private readonly ReplaceFile $replace, // Caso de uso ReplaceFile
        private readonly UploadProfileRegistry $profiles, // Registro de perfiles
        private readonly TenantContextInterface $tenantContext, // Contexto tenant
        private readonly LoggerInterface $logger, // Logger de auditoría
    ) {}

    /**
     * Actualiza el avatar del usuario con el archivo proporcionado.
     *
     * @param User $user Modelo que posee el avatar.
     * @param HttpUploadedMedia $file El archivo de imagen subido por el usuario.
     * @param string|null $uploadUuid UUID opcional para trazabilidad.
     *
     */
    public function __invoke(User $user, HttpUploadedMedia $file, ?string $uploadUuid = null): ReplacementResult // Ejecuta reemplazo
    {
        $profile = $this->profiles->get(new UploadProfileId('avatar_image')); // Obtiene perfil avatar
        $uuid = $uploadUuid ?? (string) Str::uuid(); // Genera UUID si no existe
        $result = ($this->replace)(
            $profile, // Perfil avatar
            $user, // Actor/owner
            $file, // Archivo subido
            $user->getKey() // OwnerId
        ); // Ejecuta reemplazo

        $tenantId = $this->tenantContext->requireTenantId();
        ProcessLatestAvatar::rememberLatest(
            $tenantId,
            $user->getKey(),
            $result->new->id,
            $uuid,
            $result->new->correlationId
        );
        ProcessLatestAvatar::enqueueOnce($tenantId, $user->getKey());

        $this->logger->info('avatar.upload.enqueued', [
            'user_id' => $user->getKey(), // ID de usuario
            'profile' => 'avatar_image', // Perfil usado
            'upload_uuid' => $uuid, // UUID de la subida
            'correlation_id' => $result->new->correlationId, // Correlación propagada
        ]);

        return $result; // Devuelve resultado de reemplazo
    }
}
