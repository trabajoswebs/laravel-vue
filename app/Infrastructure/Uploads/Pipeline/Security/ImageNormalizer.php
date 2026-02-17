<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security;

use App\Modules\Uploads\Contracts\FileConstraints;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Clase encargada de normalizar y reemplazar binarios de imagen con outputs controlados.
 *
 * Esta clase se utiliza dentro de `SecureImageValidation` para re-encodear imágenes sospechosas
 * sin modificar la extensión original manejada por el request. Proporciona métodos para
 * re-codificar imágenes y sobrescribir archivos de forma segura y atómica.
 */
final class ImageNormalizer
{
    /**
     * Tamaño máximo en bytes permitido para los archivos normalizados.
     */
    private readonly int $maxBytes;
    private readonly int $maxEdge;
    private readonly float $maxMegapixels;

    /**
     * Constructor de la clase.
     *
     * Valida que la calidad esté en el rango permitido y resuelve las restricciones de archivo.
     *
     * @param FileConstraints $constraints Restricciones de archivo activas.
     * @param LoggerInterface|null $logger Logger opcional para registrar eventos. Puede ser null.
     * @param int $quality Calidad de compresión para la imagen normalizada, entre 0 y 100.
     * @throws InvalidArgumentException Si la calidad no está entre 0 y 100.
     */
    public function __construct(
        FileConstraints $constraints,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $quality = 90,
    ) {
        // Valida que la calidad esté en el rango permitido
        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('ImageNormalizer quality must be between 0 and 100.');
        }

        // Establece el tamaño máximo de bytes, asegurando que sea al menos 1
        $this->maxBytes = max(1, $constraints->maxBytes);
        $this->maxEdge = max(1, (int) config('image-pipeline.max_edge', 16384));
        $this->maxMegapixels = max(0.0, (float) config('image-pipeline.max_megapixels', 48.0));
    }

    /**
     * Re-encodea la imagen usando el formato más seguro disponible.
     *
     * Intenta usar métodos específicos de formato (toPng, toJpeg) si están disponibles
     * en el objeto de imagen. Si no, utiliza un fallback con el método save().
     *
     * @param object $image Objeto de imagen soportado por Intervention (versión 2 o 3).
     * @param string|null $preferredMime MIME preferido para el resultado. Si es null, se elige un formato seguro por defecto.
     * @return string|null El binario de la imagen re-codificada como string, o null si falla.
     */
    public function reencode(object $image, ?string $preferredMime = null): ?string
    {
        $dimensions = $this->resolveDimensions($image);
        if ($dimensions !== null && ! $this->isDimensionsAllowed($dimensions[0], $dimensions[1])) {
            $this->logger?->warning('image_normalizer_dimensions_exceeded', [
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'max_edge' => $this->maxEdge,
                'max_megapixels' => $this->maxMegapixels,
            ]);

            return null;
        }

        // Elige el formato seguro basado en el MIME preferido
        $format = $this->chooseSafeFormat($preferredMime);
        $method = null;

        // Determina el método de codificación adecuado según el formato
        if ($format === 'png' && method_exists($image, 'toPng')) {
            $method = 'toPng';
        } elseif (in_array($format, ['jpg', 'jpeg'], true) && method_exists($image, 'toJpeg')) {
            $method = 'toJpeg';
        }

        // Si se encontró un método compatible, intenta codificar la imagen
        if ($method !== null) {
            try {
                // Codifica la imagen con la calidad configurada
                $encoded = $image->{$method}($this->quality);
                // Convierte el resultado codificado a string
                $bytes = $this->stringifyEncoded($encoded);
                // Si se obtuvieron bytes válidos, devuélvelos
                if (is_string($bytes) && $bytes !== '') {
                    if (strlen($bytes) > $this->maxBytes) {
                        $this->logger?->warning('image_normalizer_max_bytes_exceeded', [
                            'limit' => $this->maxBytes,
                            'size' => strlen($bytes),
                        ]);
                        return null;
                    }
                    return $bytes;
                }
            } catch (Throwable) {
                // En caso de error, continúa con el fallback para no abortar la validación
            }
        }

        // Fallback: si no se pudo usar un método específico, usa el método save() del objeto
        if (method_exists($image, 'save')) {
            // Crea un archivo temporal para guardar la imagen
            $tmp = tempnam(sys_get_temp_dir(), 'norm_img_');
            if ($tmp === false) {
                return null; // Si no se puede crear el archivo temporal, devuelve null
            }

            try {
                // Guarda la imagen en el archivo temporal
                $image->save($tmp);
                // Lee los bytes del archivo temporal
                $bytes = file_get_contents($tmp);
                // Devuelve los bytes si son válidos
                if (! is_string($bytes) || $bytes === '') {
                    return null;
                }
                if (strlen($bytes) > $this->maxBytes) {
                    $this->logger?->warning('image_normalizer_max_bytes_exceeded', [
                        'limit' => $this->maxBytes,
                        'size' => strlen($bytes),
                    ]);
                    return null;
                }

                return $bytes;
            } finally {
                // Asegura que el archivo temporal se elimine
                if (file_exists($tmp) && !@unlink($tmp)) {
                    // Registra un debug si no se pudo eliminar el archivo temporal
                    $this->logger?->debug('image_normalizer_temp_cleanup_failed');
                }
            }
        }

        // Si no se pudo codificar de ninguna manera, devuelve null
        return null;
    }

    /**
     * Escribe de forma atómica los bytes normalizados sobre el archivo original.
     *
     * Este método garantiza que la escritura del archivo sea atómica, minimizando
     * el riesgo de corrupción de datos si el proceso se interrumpe.
     *
     * @param string $path Ruta al archivo original que se va a sobrescribir.
     * @param string $bytes Contenido binario de la imagen normalizada que se va a escribir.
     * @return bool `true` si la operación fue exitosa, `false` en caso contrario.
     */
    public function overwrite(string $path, string $bytes): bool
    {
        // Verifica que el tamaño de los bytes no exceda el límite configurado
        $size = strlen($bytes);
        if ($size <= 0) {
            return false;
        }
        if ($size > $this->maxBytes) {
            // Registra un warning si se excede el tamaño máximo
            $this->logger?->warning('image_normalizer_max_bytes_exceeded', [
                'limit' => $this->maxBytes,
                'size' => $size,
            ]);
            return false; // Devuelve false si excede el tamaño
        }

        // Obtiene el directorio del archivo original
        $directory = dirname($path);
        // Si el directorio es vacío o '.', usa el directorio temporal del sistema
        if ($directory === '' || $directory === '.') {
            $directory = sys_get_temp_dir();
        }

        // Crea un archivo temporal en el directorio adecuado
        $tempPath = tempnam($directory, 'sec_norm_');
        if ($tempPath === false) {
            return false; // Si no se puede crear el archivo temporal, devuelve false
        }

        // Escribe los bytes en el archivo temporal con bloqueo exclusivo
        if (file_put_contents($tempPath, $bytes, LOCK_EX) === false) {
            // Si falla la escritura, elimina el archivo temporal y devuelve false
            @unlink($tempPath);
            return false;
        }

        // Establece permisos restrictivos (solo lectura/escritura para el propietario)
        @chmod($tempPath, 0600);

        // Renombra el archivo temporal al archivo original (operación atómica en la mayoría de los sistemas)
        if (!@rename($tempPath, $path)) {
            // Si falla el renombrado, elimina el archivo temporal y devuelve false
            @unlink($tempPath);
            return false;
        }

        // Si todo fue exitoso, devuelve true
        return true;
    }

    /**
     * Elige un formato seguro basado en el MIME preferido.
     *
     * @param string|null $preferredMime MIME preferido para la imagen.
     * @return string El formato seguro elegido ('jpeg' o 'png').
     */
    private function chooseSafeFormat(?string $preferredMime): string
    {
        if (! is_string($preferredMime) || $preferredMime === '') {
            return 'png';
        }

        $preferred = strtolower($preferredMime);

        // Conserva flujo JPEG cuando sea explícitamente JPEG.
        if (str_contains($preferred, 'jpeg') || str_contains($preferred, 'jpg')) {
            return 'jpeg';
        }

        // Para PNG/GIF/WebP/AVIF, normaliza a PNG para evitar inconsistencias de alpha/animación.
        return 'png';
    }

    /**
     * Convierte el resultado codificado de la imagen a un string.
     *
     * Intenta diferentes métodos para obtener la representación string
     * del objeto codificado, manejando posibles errores.
     *
     * @param mixed $encoded Resultado de la operación de codificación.
     * @return string|null La representación string del contenido codificado, o null si falla.
     */
    private function stringifyEncoded(mixed $encoded): ?string
    {
        // Si ya es un string, devuélvelo directamente
        if (is_string($encoded)) {
            return $encoded;
        }

        // Si es un objeto, intenta diferentes métodos para obtener su representación string
        if (is_object($encoded)) {
            // Lista de métodos potenciales para obtener la representación string
            foreach (['toString', '__toString', 'getEncoded'] as $method) {
                // Si el objeto tiene el método, intenta usarlo
                if (method_exists($encoded, $method)) {
                    try {
                        // Intenta llamar al método y convertir el resultado a string
                        return (string) $encoded->{$method}();
                    } catch (Throwable) {
                        // Si falla, continúa con el siguiente método
                    }
                }
            }
        }

        // Si no se pudo convertir a string, devuelve null
        return null;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private function resolveDimensions(object $image): ?array
    {
        $width = null;
        $height = null;

        foreach ([['width', 'height'], ['getWidth', 'getHeight']] as [$wMethod, $hMethod]) {
            if (method_exists($image, $wMethod) && method_exists($image, $hMethod)) {
                try {
                    $width = (int) $image->{$wMethod}();
                    $height = (int) $image->{$hMethod}();
                    break;
                } catch (Throwable) {
                    $width = null;
                    $height = null;
                }
            }
        }

        if (($width === null || $height === null) && method_exists($image, 'size')) {
            try {
                $size = $image->size();
                if (is_object($size) && method_exists($size, 'width') && method_exists($size, 'height')) {
                    $width = (int) $size->width();
                    $height = (int) $size->height();
                }
            } catch (Throwable) {
                $width = null;
                $height = null;
            }
        }

        if (! is_int($width) || ! is_int($height) || $width <= 0 || $height <= 0) {
            return null;
        }

        return [$width, $height];
    }

    private function isDimensionsAllowed(int $width, int $height): bool
    {
        if ($width > $this->maxEdge || $height > $this->maxEdge) {
            return false;
        }

        if ($this->maxMegapixels <= 0.0) {
            return true;
        }

        $megapixels = ($width * $height) / 1_000_000;

        return $megapixels <= $this->maxMegapixels;
    }
}
