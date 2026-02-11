<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clase encargada de responder solicitudes HTTP para servir archivos multimedia.
 *
 * Esta clase decide cómo servir un archivo dependiendo del disco de almacenamiento:
 * - Para discos locales, sirve el archivo directamente con headers de cache apropiados.
 * - Para discos como S3, genera una URL temporal y redirige al cliente.
 */
final class MediaServingResponder
{
    /**
     * Sirve un archivo desde un disco específico.
     *
     * @param string $disk El nombre del disco de almacenamiento (por ejemplo, 'local', 's3').
     * @param string $path La ruta del archivo dentro del disco.
     * @param array $extraHeaders Headers HTTP adicionales a incluir en la respuesta.
     * @return Response La respuesta HTTP que contiene el archivo o una redirección.
     */
    public function serve(string $disk, string $path, array $extraHeaders = []): Response
    {
        $path = $this->sanitizePath($path);

        // Obtiene el adaptador del disco desde el facade de Storage de Laravel
        $adapter = Storage::disk($disk);
        
        // Obtiene el driver del disco desde la configuración
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        // Si el driver NO es local y el adaptador soporta URLs temporales (como S3)
        if ($driver !== 'local' && method_exists($adapter, 'temporaryUrl')) {
            // Para servicios externos como S3, se usa cache control restrictivo
            $cacheControl = 'private, max-age=0, no-store';
            
            // Genera una URL temporal con tiempo de vida limitado
            $url = $adapter->temporaryUrl(
                $path,
                now()->addSeconds($this->temporaryUrlTtlSeconds()), // Fecha de expiración
                $this->temporaryUrlOptions($extraHeaders, $cacheControl), // Opciones para la URL
            );

            // Retorna una redirección HTTP 302 a la URL temporal
            return redirect()->away($url, 302)->withHeaders(array_merge(
                $this->baseHeaders($cacheControl),
                $this->cacheCompatHeaders($cacheControl),
                $extraHeaders, // Headers adicionales proporcionados
            ));
        }

        if ($driver !== 'local') {
            throw new RuntimeException(sprintf(
                'Disk "%s" must support temporaryUrl for private media serving.',
                $disk
            ));
        }

        // Si es un disco local, sirve el archivo directamente
        $cacheControl = 'private, max-age=' . $this->localMaxAgeSeconds() . ', must-revalidate';
        $headers = array_merge(
            $this->baseHeaders($cacheControl),
            $this->localValidators($adapter, $path),
            $this->cacheCompatHeaders($cacheControl),
            $extraHeaders, // Headers adicionales proporcionados
        );

        // Retorna la respuesta directa del archivo desde el disco local
        return $adapter->response($path, null, $headers);
    }

    /**
     * Prepara las opciones para generar una URL temporal.
     *
     * @param array $extraHeaders Headers adicionales proporcionados.
     * @param string $cacheControl Valor del header Cache-Control.
     * @return array Opciones formateadas para el método temporaryUrl.
     */
    private function temporaryUrlOptions(array $extraHeaders, string $cacheControl): array
    {
        // Inicializa las opciones con el cache control
        $options = ['ResponseCacheControl' => $cacheControl];

        // Mapea headers relacionados con el tipo de contenido
        if (isset($extraHeaders['ResponseContentType'])) {
            $options['ResponseContentType'] = $extraHeaders['ResponseContentType'];
        } elseif (isset($extraHeaders['Content-Type'])) {
            $options['ResponseContentType'] = $extraHeaders['Content-Type'];
        }

        // Mapea headers relacionados con la disposición del contenido (inline/attachment)
        if (isset($extraHeaders['ResponseContentDisposition'])) {
            $options['ResponseContentDisposition'] = $extraHeaders['ResponseContentDisposition'];
        } elseif (isset($extraHeaders['Content-Disposition'])) {
            $options['ResponseContentDisposition'] = $extraHeaders['Content-Disposition'];
        }

        return $options;
    }

    /**
     * @return array<string,string>
     */
    private function baseHeaders(string $cacheControl): array
    {
        return [
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; img-src 'self' data: blob:; media-src 'self' blob:; script-src 'none'; style-src 'none'",
            'Vary' => 'Authorization, Cookie',
        ];
    }

    /**
     * Headers de compatibilidad para caches legacy/intermedios.
     *
     * @return array<string,string>
     */
    private function cacheCompatHeaders(string $cacheControl): array
    {
        if (!str_contains($cacheControl, 'no-store')) {
            return [];
        }

        return [
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function localValidators(mixed $adapter, string $path): array
    {
        if (! method_exists($adapter, 'lastModified') || ! method_exists($adapter, 'size')) {
            return [];
        }

        try {
            $lastModified = (int) $adapter->lastModified($path);
            $size = (int) $adapter->size($path);
            if ($lastModified <= 0 || $size < 0) {
                return [];
            }

            return [
                'Last-Modified' => gmdate(DATE_RFC7231, $lastModified),
                'ETag' => sprintf('W/"%s"', sha1($path . '|' . $size . '|' . $lastModified)),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Obtiene el tiempo de vida máximo en segundos para archivos locales en cache.
     *
     * @return int Segundos de cache para archivos locales.
     */
    private function localMaxAgeSeconds(): int
    {
        // Nunca cachear por más tiempo que la validez de la URL temporal.
        $maxAgeSeconds = (int) config('media-serving.local_max_age_seconds', 86400);
        $maxAgeSeconds = $maxAgeSeconds > 0 ? $maxAgeSeconds : 86400;

        return min($maxAgeSeconds, $this->temporaryUrlTtlSeconds());
    }

    /**
     * TTL unificado para URLs temporales/signed media.
     *
     * @return int Segundos de vida para URLs temporales.
     */
    private function temporaryUrlTtlSeconds(): int
    {
        // Obtiene el valor de configuración, con un fallback de 900 segundos (15 minutos)
        $seconds = (int) config('media-serving.temporary_url_ttl_seconds', 900);

        // Asegura que sea un valor positivo
        return $seconds > 0 ? $seconds : 900;
    }

    private function sanitizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.'
        ));

        if ($segments === [] || in_array('..', $segments, true)) {
            throw new RuntimeException('Invalid media path.');
        }

        $clean = implode('/', $segments);
        if (!str_starts_with($clean, 'tenants/')) {
            throw new RuntimeException('Media path must be tenant-first.');
        }

        return $clean;
    }
}
