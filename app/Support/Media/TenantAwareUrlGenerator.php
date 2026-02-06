<?php // UrlGenerator tenant-aware para Spatie que evita /storage y enruta por controlador protegido

declare(strict_types=1); // Fuerza tipado estricto

namespace App\Support\Media; // Namespace de soporte para media // Ej: app('media.url_generator')

use DateTimeInterface; // Tipo para expiraciones de temporaryUrl // Ej: now()->addMinutes(15)
use Illuminate\Contracts\Filesystem\Filesystem; // Contrato de discos Laravel // Ej: Storage::disk('s3')
use Illuminate\Support\Facades\Storage; // Facade de Storage para disco actual // Ej: Storage::disk($name)
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator; // UrlGenerator base de Spatie // Ej: getUrl()

final class TenantAwareUrlGenerator extends DefaultUrlGenerator // Extiende generador por defecto // Ej: se usa vía config
{
    /**
     * Genera URL para media o conversion
     * Evita el uso de /storage y enruta a través de controlador protegido
     * Soporta tanto discos locales como remotos (S3, etc.)
     * 
     * @return string URL generada para el archivo de media
     */
    public function getUrl(): string // Genera URL para media o conversion // Ej: avatar thumb
    {
        $diskName = $this->getDiskName(); // Nombre del disk actual (original o conversions) // Ej: avatars
        $driver = (string) config("filesystems.disks.{$diskName}.driver", 'local'); // Driver del disk // Ej: local|s3
        $relativePath = $this->getPathRelativeToRoot(); // Path relativo con tenant incluido // Ej: tenants/1/users/2/abc.jpg

        if ($driver === 'local') { // Para discos locales evitamos /storage // Ej: dev sin storage:link
            $encoded = implode('/', array_map('rawurlencode', explode('/', $relativePath))); // Codifica cada segmento sin tocar los slashes
            $url = url('media/' . $encoded); // Construye URL limpia sin %2F
            return $this->versionUrl($url); // Aplica versionado si está habilitado // Ej: /media/...?...v=123
        }

        $disk = $this->getDisk(); // Obtiene FilesystemAdapter configurado // Ej: S3 adapter

        if (method_exists($disk, 'temporaryUrl')) { // Si el driver soporta URLs temporales // Ej: s3
            $url = $this->buildTemporaryUrl($disk, $relativePath); // Genera temporaryUrl con expiración corta // Ej: firma S3
            return $this->versionUrl($url); // Retorna con versión opcional // Ej: añade ?v=...
        }

        $url = $disk->url($relativePath); // Fallback a URL pública del disk // Ej: CDN configurado
        return $this->versionUrl($url); // Retorna aplicando versionado // Ej: ?v=timestamp
    }

    /**
     * Genera URL temporal con expiración específica
     * Requerido por la interfaz de Spatie Media Library
     * 
     * @param DateTimeInterface $expiration Fecha de expiración de la URL
     * @param array $options Opciones adicionales para la URL
     * @return string URL temporal firmada
     */
    public function getTemporaryUrl(DateTimeInterface $expiration, array $options = []): string // Firma explícita requerida por interfaz // Ej: media->getTemporaryUrl()
    {
        $disk = $this->getDisk(); // Obtiene disk actual // Ej: S3
        $relativePath = $this->getPathRelativeToRoot(); // Path relativo // Ej: tenants/1/...
        $url = $disk->temporaryUrl($relativePath, $expiration, $options); // Crea URL temporal // Ej: expira en $expiration
        return $this->versionUrl($url); // Aplica versionado si procede // Ej: ?v=timestamp
    }

    /**
     * Construye URL temporal para drivers remotos
     * Utiliza TTL configurable desde config
     * 
     * @param Filesystem $disk Instancia del filesystem
     * @param string $relativePath Ruta relativa del archivo
     * @return string URL temporal generada
     */
    private function buildTemporaryUrl(Filesystem $disk, string $relativePath): string // Crea temporaryUrl para drivers remotos // Ej: s3
    {
        $ttlSeconds = (int) config('media-serving.s3_temporary_url_ttl_seconds', 900); // TTL en segundos (config única)
        $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : 900;
        $expiration = now()->addSeconds($ttlSeconds); // Asegura TTL positivo // Ej: now()+900s

        return $disk->temporaryUrl($relativePath, $expiration); // Genera URL temporal firmada // Ej: https://s3...&X-Amz-Expires=900
    }

    /**
     * Obtiene instancia del disco configurado
     * Método helper protegido para reutilizar lógica
     * 
     * @return Filesystem Instancia del filesystem
     */
    protected function getDisk(): Filesystem // Helper protegido para exponer disk, coincide con BaseUrlGenerator // Ej: reutilizar sin duplicar lógica
    {
        return Storage::disk($this->getDiskName()); // Usa Storage facade // Ej: Storage::disk('s3')
    }
}