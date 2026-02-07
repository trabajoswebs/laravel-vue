<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Http\Support;

use Illuminate\Support\Facades\Storage;
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
                now()->addSeconds($this->s3TemporaryUrlTtlSeconds()), // Fecha de expiración
                $this->temporaryUrlOptions($extraHeaders, $cacheControl), // Opciones para la URL
            );

            // Retorna una redirección HTTP 302 a la URL temporal
            return redirect()->away($url, 302)->withHeaders(array_merge(
                [
                    'Cache-Control' => $cacheControl, // Headers de cache restrictivos
                    'X-Content-Type-Options' => 'nosniff', // Header de seguridad
                ],
                $extraHeaders, // Headers adicionales proporcionados
            ));
        }

        // Si es un disco local, sirve el archivo directamente
        $headers = array_merge(
            [
                // Headers de cache para archivos locales: privado, con tiempo de vida y revalidación
                'Cache-Control' => 'private, max-age=' . $this->localMaxAgeSeconds() . ', must-revalidate',
                'X-Content-Type-Options' => 'nosniff', // Header de seguridad
            ],
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
     * Obtiene el tiempo de vida máximo en segundos para archivos locales en cache.
     *
     * @return int Segundos de cache para archivos locales.
     */
    private function localMaxAgeSeconds(): int
    {
        // Obtiene el valor de configuración, con un fallback de 86400 segundos (1 día)
        $seconds = (int) config('media-serving.local_max_age_seconds', 86400);

        // Asegura que sea un valor positivo
        return $seconds > 0 ? $seconds : 86400;
    }

    /**
     * Obtiene el tiempo de vida en segundos para las URLs temporales de S3.
     *
     * @return int Segundos de vida para URLs temporales.
     */
    private function s3TemporaryUrlTtlSeconds(): int
    {
        // Obtiene el valor de configuración, con un fallback de 900 segundos (15 minutos)
        $seconds = (int) config('media-serving.s3_temporary_url_ttl_seconds', 900);

        // Asegura que sea un valor positivo
        return $seconds > 0 ? $seconds : 900;
    }
}