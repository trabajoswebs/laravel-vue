<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Security;

use App\Modules\Uploads\Pipeline\Security\MimeNormalizer;
use App\Modules\Uploads\Pipeline\Security\Upload\UploadValidationLogger;

/**
 * Clase encargada de leer y validar metadatos de imágenes.
 *
 * Esta clase extrae metadatos de imagen sin confiar en el MIME proporcionado por el cliente.
 * Combina las funciones nativas `getimagesize`, `finfo` y heurísticas de animación para
 * validar la integridad de la imagen sin cargar grandes binarios en memoria.
 * 
 * Proporciona métodos para detectar tipos MIME confiables, verificar si una imagen es animada,
 * y leer información básica de la imagen de forma segura.
 */
final class ImageMetadataReader
{
    /**
     * Constructor de la clase.
     *
     * @param UploadValidationLogger $logger Instancia del logger para registrar eventos de validación.
     */
    public function __construct(
        private readonly UploadValidationLogger $logger,
    ) {
    }

    /**
     * Obtiene información básica de la imagen (ancho, alto y tipo MIME) manejando los warnings nativos de PHP.
     *
     * Esta función encapsula la llamada a `getimagesize` y suprime cualquier warning que pueda
     * ser generado por PHP si el archivo no es una imagen válida, devolviendo `false` en su lugar.
     *
     * @param string $path Ruta al archivo de imagen del cual se quiere obtener la información.
     * @return array<int,mixed>|false Devuelve un array con la información de la imagen en caso de éxito,
     *                                o `false` si no se puede leer la imagen o no es válida.
     */
    public function readImageInfo(string $path)
    {
        // Configura un manejador de errores que ignora los warnings de getimagesize
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            // Intenta obtener la información de la imagen
            return getimagesize($path);
        } finally {
            // Asegura que el manejador de errores original se restaura
            restore_error_handler();
        }
    }

    /**
     * Determina el MIME confiable combinando EXIF y finfo.
     *
     * Este método intenta detectar el tipo MIME real de un archivo de imagen utilizando
     * tanto la información EXIF como la de finfo, y luego aplica un callback para verificar
     * si los MIMEs detectados están permitidos. Si ambos detectores devuelven MIMEs diferentes
     * pero ambos están permitidos, prioriza el resultado de `finfo`.
     *
     * @param string $path Ruta al archivo de imagen.
     * @param callable $isMimeAllowed Callback que recibe un string MIME y devuelve un booleano indicando si está permitido.
     * @param array|null $imageInfo Información de imagen opcional previamente obtenida (para evitar llamar a getimagesize nuevamente).
     * @param string|null $filename Nombre del archivo, para fines de registro (logging).
     * @param string|int|null $userId ID del usuario que subió el archivo, para fines de registro.
     * @return string|null El MIME confiable detectado y permitido, o `null` si no se pudo determinar o ninguno era válido.
     */
    public function resolveTrustedMime(
        string $path,
        callable $isMimeAllowed,
        ?array $imageInfo = null,
        ?string $filename = null,
        string|int|null $userId = null,
    ): ?string {
        // Detecta el MIME usando EXIF (getimagesize)
        $exifMime = $this->detectExifMime($path, $imageInfo);
        // Detecta el MIME usando finfo
        $finfoMime = $this->detectFinfoMime($path);

        // Verifica si los MIMEs detectados están permitidos
        $exifAllowed = $exifMime !== null && $isMimeAllowed($exifMime);
        $finfoAllowed = $finfoMime !== null && $isMimeAllowed($finfoMime);

        // Registra un warning si el MIME EXIF no está permitido y lo invalida
        if ($exifMime !== null && !$exifAllowed) {
            $this->logger->warning('image_mime_exif_rejected', $filename ?? 'unknown', ['mime' => $exifMime], $userId);
            $exifMime = null;
        }

        // Registra un warning si el MIME finfo no está permitido y lo invalida
        if ($finfoMime !== null && !$finfoAllowed) {
            $this->logger->warning('image_mime_finfo_rejected', $filename ?? 'unknown', ['mime' => $finfoMime], $userId);
            $finfoMime = null;
        }

        // Si ambos MIMEs son diferentes y ambos son válidos, hay un conflicto
        if ($finfoMime !== null && $exifMime !== null && $finfoMime !== $exifMime) {
            // En caso de conflicto, se considera más confiable el resultado de finfo
            $this->logger->warning('image_mime_detector_mismatch', $filename ?? 'unknown', [
                'exif_mime' => $exifMime,
                'finfo_mime' => $finfoMime,
                'trusted_source' => 'finfo', // Indica que se usará el resultado de finfo
            ], $userId);

            return $finfoMime; // Devuelve el MIME de finfo como el confiable
        }

        // Devuelve el primer MIME confiable encontrado (prioridad: finfo, luego exif)
        return $finfoMime ?? $exifMime;
    }

    /**
     * Determina si una imagen es animada según su tipo MIME y contenido.
     *
     * Actualmente soporta la detección de animación para GIF y WebP.
     *
     * @param string $mime El MIME del archivo de imagen.
     * @param string $path Ruta al archivo de imagen.
     * @return bool `true` si la imagen es animada, `false` en caso contrario o si el tipo no es soportado.
     */
    public function isAnimated(string $mime, string $path): bool
    {
        // Normaliza el MIME para asegurar consistencia
        $normalized = MimeNormalizer::normalize($mime);
        if ($normalized === null) {
            // Si no se puede normalizar, no se considera animada
            return false;
        }

        // Usa un match para determinar el tipo y llamar al método adecuado
        return match ($normalized) {
            'image/webp' => $this->isAnimatedWebp($path),
            'image/gif' => $this->isAnimatedGif($path),
            // Para cualquier otro tipo, devuelve false
            default => false,
        };
    }

    /**
     * Detecta el MIME de un archivo de imagen usando la información EXIF (getimagesize).
     *
     * @param string $path Ruta al archivo de imagen.
     * @param array|null $imageInfo Información de imagen opcional previamente obtenida.
     * @return string|null El MIME detectado y normalizado, o `null` si no se pudo obtener.
     */
    private function detectExifMime(string $path, ?array $imageInfo = null): ?string
    {
        // Obtiene la información de la imagen, usando la cacheada si está disponible
        $info = $imageInfo ?? @getimagesize($path);
        // Verifica que la información sea un array y contenga el índice del tipo de imagen
        if (!is_array($info) || !isset($info[2])) {
            return null;
        }

        // Convierte el índice numérico del tipo de imagen a MIME y lo normaliza
        return MimeNormalizer::normalize(image_type_to_mime_type((int) $info[2]));
    }

    /**
     * Detecta el MIME de un archivo usando la extensión fileinfo.
     *
     * @param string $path Ruta al archivo de imagen.
     * @return string|null El MIME detectado y normalizado, o `null` si no se pudo obtener o fileinfo no está disponible.
     */
    private function detectFinfoMime(string $path): ?string
    {
        // Verifica si la función finfo_open está disponible
        if (!function_exists('finfo_open')) {
            return null;
        }

        // Intenta abrir el recurso finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            // Obtiene el MIME del archivo
            $mime = finfo_file($finfo, $path);
        } finally {
            // Asegura que el recurso finfo se cierra
            finfo_close($finfo);
        }

        // Normaliza el MIME obtenido
        return MimeNormalizer::normalize($mime);
    }

    /**
     * Verifica si un archivo GIF es animado.
     *
     * Lee el archivo binario para buscar múltiples descriptores de imagen (0x2C),
     * lo que indica que hay más de un frame y por lo tanto es animado.
     *
     * @param string $path Ruta al archivo GIF.
     * @return bool `true` si el GIF es animado, `false` en caso contrario o si hay error.
     */
    private function isAnimatedGif(string $path): bool
    {
        // Abre el archivo en modo binario de solo lectura
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            // Lee los primeros 6 bytes para verificar el encabezado 'GIF'
            $header = fread($handle, 6);
            if (!is_string($header) || !str_starts_with($header, 'GIF')) {
                return false; // No es un archivo GIF válido
            }

            $descriptorCount = 0;
            // Lee el archivo en trozos para encontrar descriptores de imagen
            while (!feof($handle)) {
                $chunk = fread($handle, 8192); // Lee 8KB a la vez
                if ($chunk === false || $chunk === '') {
                    break; // Fin de archivo o error
                }

                // Cuenta las apariciones del descriptor de imagen (0x2C)
                $descriptorCount += substr_count($chunk, "\x2C");
                if ($descriptorCount > 1) {
                    return true; // Más de un descriptor implica animación
                }
            }
        } finally {
            // Asegura que el archivo se cierra
            fclose($handle);
        }

        return false; // No se encontró más de un descriptor
    }

    /**
     * Verifica si un archivo WebP es animado.
     *
     * Lee el archivo binario para buscar chunks específicos de WebP animado ('VP8X' con bandera de animación o 'ANIM').
     *
     * @param string $path Ruta al archivo WebP.
     * @return bool `true` si el WebP es animado, `false` en caso contrario o si hay error.
     */
    private function isAnimatedWebp(string $path): bool
    {
        // Abre el archivo en modo binario de solo lectura
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            // Lee los primeros 16 bytes para verificar los encabezados RIFF y WEBP
            $header = fread($handle, 16);
            if (!is_string($header) || strlen($header) < 16) {
                return false; // Archivo demasiado pequeño
            }

            // Verifica la firma RIFF y WEBP
            if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
                return false; // No es un archivo WebP válido
            }

            // Busca el chunk VP8X o ANIM que indica animación
            // El chunk VP8X está en la posición 12 en un WebP típico
            if (fseek($handle, 12, SEEK_SET) !== 0) {
                return false; // Error al buscar la posición
            }

            // Lee chunks hasta encontrar VP8X o ANIM
            while (!feof($handle)) {
                $chunkHeader = fread($handle, 8); // Lee 8 bytes: 4 ID + 4 tamaño
                if (!is_string($chunkHeader) || strlen($chunkHeader) < 8) {
                    break; // Error o fin de archivo
                }

                $chunkId = substr($chunkHeader, 0, 4);
                // Desempaqueta el tamaño little-endian
                $chunkSize = unpack('V', substr($chunkHeader, 4, 4))[1] ?? 0;

                if ($chunkId === 'VP8X') {
                    // Lee los datos del chunk VP8X
                    $chunkData = fread($handle, $chunkSize);
                    if (!is_string($chunkData) || strlen($chunkData) < 1) {
                        break; // Error o datos insuficientes
                    }

                    // La bandera de animación es el bit 1 (0x02) en el primer byte
                    if ((ord($chunkData[0]) & 0x02) === 0x02) {
                        return true; // Chunk VP8X indica animación
                    }
                } elseif ($chunkId === 'ANIM') {
                    return true; // Chunk ANIM indica animación
                } else {
                    // Salta el contenido del chunk actual
                    if (fseek($handle, $chunkSize, SEEK_CUR) === -1) {
                        break; // Error al saltar chunk
                    }
                }

                // Los chunks RIFF deben estar alineados a 2 bytes
                if ($chunkSize % 2 === 1 && fseek($handle, 1, SEEK_CUR) === -1) {
                    break; // Saltar padding
                }
            }
        } finally {
            // Asegura que el archivo se cierra
            fclose($handle);
        }

        return false; // No se encontró indicio de animación
    }
}
