<?php // PathGenerator de Spatie aware de tenant

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Core\Paths\MediaLibrary; // Namespace para paths de media

use App\Application\Shared\Contracts\TenantContextInterface; // Contexto de tenant
use App\Domain\Uploads\UploadProfileId; // VO de perfil
use App\Infrastructure\Models\User; // Modelo User para fallback de tenant
use App\Infrastructure\Uploads\Core\Paths\TenantPathGenerator; // Generador tenant-first
use App\Infrastructure\Uploads\Core\Paths\TenantPathLayout; // Layout común tenant-first
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry; // Registro de perfiles
use Illuminate\Support\Str; // Helper de strings
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media de Spatie
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator; // Contrato de path generator

/**
 * Genera rutas de Media Library respetando tenant-first.
 */
final class TenantAwarePathGenerator implements PathGenerator // Implementa PathGenerator de Spatie
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext, // Contexto para tenant_id
        private readonly TenantPathGenerator $paths, // Generador de paths tenant-first
        private readonly UploadProfileRegistry $profiles, // Registro de perfiles
        private readonly TenantPathLayout $layout, // Layout común para extraer directorios
    ) {
    }

    /**
     * Ruta base para archivos originales.
     */
    public function getPath(Media $media): string // Devuelve path base para original
    {
        $profile = $this->profileFor($media); // Obtiene perfil de dominio
        $ownerId = $media->model_id; // ID del dueño (user)
        $tenantId = $this->resolveTenantId($media); // Obtiene tenant activo con fallback
        $ext = $media->extension ?? 'bin'; // Extensión detectada
        $version = $media->getCustomProperty('version') ?? $media->uuid ?? time(); // Versión/hash
        $versionInt = is_numeric($version) ? (int) $version : crc32((string) $version); // Normaliza versión a int

        $unique = $media->getCustomProperty('upload_uuid') ?: $media->uuid; // Usa upload_uuid si existe
        $full = $this->paths->generate($profile, $ownerId, $ext, $versionInt, $unique); // Path completo con filename usando UUID

        return $this->layout->baseDirectory($full); // Devuelve directorio con slash final
    }

    /**
     * Ruta base para conversions.
     */
    public function getPathForConversions(Media $media): string // Devuelve path para conversions
    {
        return $this->getPath($media) . 'conversions/'; // Reusa path base y agrega conversions
    }

    /**
     * Ruta base para responsive images.
     */
    public function getPathForResponsiveImages(Media $media): string // Devuelve path para responsive images
    {
        return $this->getPath($media) . 'responsive-images/'; // Reusa path base y agrega responsive-images
    }

    /**
     * Determina nombre base del archivo.
     */
    private function baseFileName(Media $media): string // Genera nombre base
    {
        $version = $media->getCustomProperty('version') ?? $media->uuid ?? Str::uuid()->toString(); // Version/hash
        return 'v' . $version; // Nombre base con prefijo v
    }

    /**
     * Obtiene perfil de dominio según colección.
     */
    private function profileFor(Media $media): \App\Domain\Uploads\UploadProfile // Devuelve perfil correspondiente
    {
        $collection = $media->collection_name; // Nombre de colección
        $profileId = $collection === 'avatar' ? 'avatar_image' : 'gallery_image'; // Mapea colección a perfil

        return $this->profiles->get(new UploadProfileId($profileId)); // Devuelve perfil del registro
    }

    /**
     * Resuelve tenant_id usando contexto o propiedades del media/owner.
     *
     * @param Media $media Media actual
     * @return int|string Tenant ID
     */
    private function resolveTenantId(Media $media): int|string // Determina tenant_id
    {
        $tenantId = $this->tenantContext->tenantId(); // Intenta desde contexto

        if ($tenantId !== null) { // Si existe
            return $tenantId; // Usa el del contexto
        }

        $propTenant = $media->getCustomProperty('tenant_id'); // Lee de propiedades custom
        if ($propTenant !== null && $propTenant !== '') { // Si existe
            return $propTenant; // Usa el guardado
        }

        $owner = $media->model; // Obtiene modelo owner
        if ($owner instanceof User && $owner->current_tenant_id !== null) { // Usa tenant del owner
            return $owner->current_tenant_id; // Devuelve tenant del owner
        }

        return $this->tenantContext->requireTenantId(); // Último recurso: exige tenant
    }
}
