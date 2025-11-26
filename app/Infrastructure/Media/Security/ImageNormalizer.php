<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Security;

use App\Domain\User\ConversionProfiles\FileConstraints;
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
    private int $maxBytes;

    /**
     * Constructor de la clase.
     *
     * Valida que la calidad esté en el rango permitido y resuelve las restricciones de archivo.
     *
     * @param LoggerInterface|null $logger Logger opcional para registrar eventos. Puede ser null.
     * @param int $quality Calidad de compresión para la imagen normalizada, entre 0 y 100.
     * @param FileConstraints|null $constraints Opcionalmente se inyectan las restricciones globales. Si es null, se obtienen desde el contenedor de Laravel.
     * @throws InvalidArgumentException Si la calidad no está entre 0 y 100.
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly int $quality = 90,
        ?FileConstraints $constraints = null,
    ) {
        // Valida que la calidad esté en el rango permitido
        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('ImageNormalizer quality must be between 0 and 100.');
        }

        // Resuelve las restricciones de archivo, usando las inyectadas o obteniendo las globales
        $resolvedConstraints = $constraints ?? app(FileConstraints::class);
        // Establece el tamaño máximo de bytes, asegurando que sea al menos 1
        $this->maxBytes = max(1, $resolvedConstraints->maxBytes);
    }

    /**
     * Re-encodea la imagen usando el formato más seguro disponible.
     *
     * Intenta usar métodos específicos de formato (toPng, toJpeg, toWebp) si están disponibles
     * en el objeto de imagen. Si no, utiliza un fallback con el método save().
     *
     * @param object $image Objeto de imagen soportado por Intervention (versión 2 o 3).
     * @param string|null $preferredMime MIME preferido para el resultado. Si es null, se elige un formato seguro por defecto.
     * @return string|null El binario de la imagen re-codificada como string, o null si falla.
     */
    public function reencode(object $image, ?string $preferredMime = null): ?string
    {
        // Elige el formato seguro basado en el MIME preferido
        $format = $this->chooseSafeFormat($preferredMime);
        $method = null;

        // Determina el método de codificación adecuado según el formato
        if ($format === 'png' && method_exists($image, 'toPng')) {
            $method = 'toPng';
        } elseif (in_array($format, ['jpg', 'jpeg'], true) && method_exists($image, 'toJpeg')) {
            $method = 'toJpeg';
        } elseif ($format === 'webp' && method_exists($image, 'toWebp')) {
            $method = 'toWebp';
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
                return is_string($bytes) && $bytes !== '' ? $bytes : null;
            } finally {
                // Asegura que el archivo temporal se elimine
                if (file_exists($tmp) && !@unlink($tmp)) {
                    // Registra un debug si no se pudo eliminar el archivo temporal
                    $this->logger?->debug('image_normalizer_temp_cleanup_failed', ['path' => $tmp]);
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
        // Si el MIME preferido contiene 'jpeg', devuelve 'jpeg'
        if (is_string($preferredMime) && str_contains($preferredMime, 'jpeg')) {
            return 'jpeg';
        }

        // Por defecto, devuelve 'png' como formato seguro
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
}