<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Paths;

use App\Domain\Uploads\UploadProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Encapsula el layout tenant-first usado por los generadores de paths.
 * Mantiene compatibilidad exacta con el layout existente.
 */
final class TenantPathLayout
{
    /**
    * Genera el path final (incluyendo filename) para un perfil dado.
    *
    * @param UploadProfile $profile Perfil de upload
    * @param int|string $tenantId Tenant ID obligatorio
    * @param int|string|null $ownerId Owner opcional (requerido para avatars)
    * @param string $extension Extensión final normalizada
    * @param int|null $version Versión cuando aplica (avatars)
    * @param string|null $uniqueId UUID opcional para estabilizar filenames
    * @param Carbon|null $date Fecha a usar para carpetas dated (por defecto now())
    *
    * @return string Path relativo en disco incluyendo filename
    */
    public function pathForProfile(
        UploadProfile $profile,
        int|string $tenantId,
        int|string|null $ownerId,
        string $extension,
        ?int $version,
        ?string $uniqueId,
        ?Carbon $date = null,
    ): string {
        $date = $date ?? now();

        return match ($profile->pathCategory) {
            'avatars' => $this->avatarPath($tenantId, $ownerId, $extension, $version ?? time(), $uniqueId),
            'images' => $this->datedPath($tenantId, 'media/images', $extension, $date),
            'documents' => $this->datedFixedPath($tenantId, 'documents', 'pdf', $date),
            'spreadsheets' => $this->datedFixedPath($tenantId, 'spreadsheets', 'xlsx', $date),
            'imports' => $this->datedFixedPath($tenantId, 'imports', 'csv', $date),
            'secrets' => $this->secretsPath($tenantId, 'certificates', 'p12'),
            default => $this->datedPath($tenantId, 'uploads', $extension, $date),
        };
    }

    /**
     * Devuelve el directorio contenedor (con slash final) de un path de archivo.
     */
    public function baseDirectory(string $fullPath): string
    {
        $dir = Str::beforeLast($fullPath, '/');

        return rtrim($dir, '/') . '/';
    }

    /**
     * Devuelve el directorio de conversions relativo a un archivo.
     */
    public function conversionsDirectory(string $fullPath): string
    {
        return $this->baseDirectory($fullPath) . 'conversions/';
    }

    private function avatarPath(int|string $tenantId, int|string|null $ownerId, string $extension, int $version, ?string $uniqueId): string
    {
        if ($ownerId === null) {
            throw new \InvalidArgumentException('ownerId requerido para avatar');
        }

        $uuid = $uniqueId ?: (string) Str::uuid();

        return sprintf(
            'tenants/%s/users/%s/avatars/%s/v%s.%s',
            $tenantId,
            $ownerId,
            $uuid,
            $version,
            $extension
        );
    }

    private function datedPath(int|string $tenantId, string $base, string $extension, Carbon $date): string
    {
        $uuid = Str::uuid()->toString();
        $year = $date->format('Y');
        $month = $date->format('m');

        return sprintf('tenants/%s/%s/%s/%s/%s.%s', $tenantId, trim($base, '/'), $year, $month, $uuid, $extension);
    }

    private function datedFixedPath(int|string $tenantId, string $category, string $extension, Carbon $date): string
    {
        return $this->datedPath($tenantId, $category, $extension, $date);
    }

    private function secretsPath(int|string $tenantId, string $category, string $extension): string
    {
        $uuid = Str::uuid()->toString();

        return sprintf('tenants/%s/secrets/%s/%s.%s', $tenantId, trim($category, '/'), $uuid, $extension);
    }
}
