<?php // Generador de paths tenant-first para uploads

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Core\Paths; // Namespace de infraestructura de uploads

use App\Application\Shared\Contracts\TenantContextInterface; // Contexto de tenant
use App\Domain\Uploads\UploadProfile; // Perfil de upload
use Illuminate\Support\Str; // Helper para UUID y strings

/**
 * Genera rutas finales para archivos subidos respetando el tenant.
 */
class TenantPathGenerator // Servicio para construir paths
{
    public function __construct(private readonly TenantContextInterface $tenantContext) // Injerta contexto de tenant
    {
    }

    /**
     * Genera el path final para un archivo según el perfil.
     *
     * @param UploadProfile $profile Perfil de subida
     * @param int|string|null $ownerId Identificador del owner (opcional)
     * @param string $extension Extensión normalizada (ej. jpg)
     * @param int|null $version Versión para avatars
     * @param string|null $uniqueId Identificador único opcional (UUID)
     * @return string Path relativo en el disco
     */
    public function generate(UploadProfile $profile, int|string|null $ownerId, string $extension, ?int $version = null, ?string $uniqueId = null): string // Devuelve path tenant-first
    {
        $tenantId = $this->tenantContext->requireTenantId(); // Obtiene tenant_id activo o lanza
        $date = now(); // Fecha actual para carpetas año/mes

        return match ($profile->pathCategory) { // Selecciona categoría de path
            'avatars' => $this->avatarPath($tenantId, $ownerId, $extension, $version ?? time(), $uniqueId), // Path para avatar con versión
            'images' => $this->datedPath($tenantId, 'media/images', $extension, $date), // Path para imágenes generales
            'documents' => $this->datedFixedPath($tenantId, 'documents', 'pdf', $date), // Path para PDFs
            'spreadsheets' => $this->datedFixedPath($tenantId, 'spreadsheets', 'xlsx', $date), // Path para XLSX
            'imports' => $this->datedFixedPath($tenantId, 'imports', 'csv', $date), // Path para CSV
            'secrets' => $this->secretsPath($tenantId, 'certificates', 'p12'), // Path para certificados
            default => $this->datedPath($tenantId, 'uploads', $extension, $date), // Path genérico de fallback
        };
    }

    /**
     * Path para avatars con versión incremental.
     *
     * @param int|string $tenantId Tenant ID
     * @param int|string|null $ownerId Owner ID (requerido para avatar)
     * @param string $extension Extensión (ej. jpg)
     * @param int $version Versión del avatar
     * @param string|null $uniqueId UUID opcional para separar versiones
     * @return string Path relativo
     */
    private function avatarPath(int|string $tenantId, int|string|null $ownerId, string $extension, int $version, ?string $uniqueId): string // Construye path de avatar
    {
        if ($ownerId === null) { // Valida owner presente
            throw new \InvalidArgumentException('ownerId requerido para avatar'); // Error si falta owner
        }

        $uuid = $uniqueId ?: (string) \Illuminate\Support\Str::uuid(); // Usa UUID estable si existe

        return sprintf( // Concatena path siguiendo convención con carpeta por versión
            'tenants/%s/users/%s/avatars/%s/v%s.%s',
            $tenantId,
            $ownerId,
            $uuid,
            $version,
            $extension
        );
    }

    /**
     * Path con fecha + UUID para categorías genéricas.
     *
     * @param int|string $tenantId Tenant ID
     * @param string $base Directorio base (ej. media/images)
     * @param string $extension Extensión final
     * @param \Illuminate\Support\Carbon $date Fecha actual
     * @return string Path relativo
     */
    private function datedPath(int|string $tenantId, string $base, string $extension, \Illuminate\Support\Carbon $date): string // Path con carpetas año/mes
    {
        $uuid = Str::uuid()->toString(); // Genera UUID
        $year = $date->format('Y'); // Año actual
        $month = $date->format('m'); // Mes actual

        return sprintf('tenants/%s/%s/%s/%s/%s.%s', $tenantId, trim($base, '/'), $year, $month, $uuid, $extension); // Construye path completo
    }

    /**
     * Path con fecha y extensión fija.
     *
     * @param int|string $tenantId Tenant ID
     * @param string $category Categoría base (documents/imports/etc.)
     * @param string $extension Extensión fija
     * @param \Illuminate\Support\Carbon $date Fecha actual
     * @return string Path relativo
     */
    private function datedFixedPath(int|string $tenantId, string $category, string $extension, \Illuminate\Support\Carbon $date): string // Path con extensión fija
    {
        return $this->datedPath($tenantId, $category, $extension, $date); // Reutiliza datedPath con extensión fija
    }

    /**
     * Path para secretos/certificados con UUID.
     *
     * @param int|string $tenantId Tenant ID
     * @param string $category Categoría (ej. certificates)
     * @param string $extension Extensión final (ej. p12)
     * @return string Path relativo
     */
    private function secretsPath(int|string $tenantId, string $category, string $extension): string // Path para secretos
    {
        $uuid = Str::uuid()->toString(); // Genera UUID único

        return sprintf('tenants/%s/secrets/%s/%s.%s', $tenantId, trim($category, '/'), $uuid, $extension); // Construye path secreto
    }
}
