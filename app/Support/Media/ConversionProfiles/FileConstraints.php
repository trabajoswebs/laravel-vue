<?php

declare(strict_types=1);

namespace App\Support\Media\ConversionProfiles;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * FileConstraints
 *
 * Value object centralizado que encapsula los límites y parámetros permitidos
 * para imágenes. Lee su configuración desde config/image-pipeline.php y expone
 * helpers para validaciones de requests, reglas y pipelines de procesamiento.
 *
 * Mantiene compatibilidad con los valores por defecto previos mediante
 * constantes, pero tras la construcción expone propiedades readonly
 * a las que debe apuntar el resto del código.
 */
final class FileConstraints
{
    /** @var int bytes */
    // Tamaño máximo permitido para un archivo de imagen, en bytes.
    public readonly int $maxBytes;

    /** @var int píxeles */
    // Dimensión mínima permitida para el ancho o alto de la imagen, en píxeles.
    public readonly int $minDimension;

    /** @var int píxeles */
    // Dimensión máxima permitida para el ancho o alto de la imagen, en píxeles.
    public readonly int $maxDimension;

    /** @var float megapíxeles */
    // Límite máximo de megapíxeles (ancho * alto / 1,000,000) permitidos para la imagen.
    public readonly float $maxMegapixels;

    /** @var array<int,string> */
    // Lista de extensiones de archivo permitidas (p. ej., ['jpg', 'png']).
    public readonly array $allowedExtensions;

    /** @var array<int,string> */
    // Lista de tipos MIME permitidos (p. ej., ['image/jpeg', 'image/png']).
    public readonly array $allowedMimeTypes;

    /** @var array<string,string> */
    // Mapa que relaciona un tipo MIME con su extensión recomendada (p. ej., ['image/jpeg' => 'jpg']).
    public readonly array $allowedMimeMap;

    /** @var bool */
    // Indica si las conversiones de imagen deben encolarse por defecto.
    public readonly bool $queueConversionsDefault;

    /** @var bool|null */
    // Preferencia específica para avatares: si deben encolarse o no. Puede ser null si no está definida.
    public readonly ?bool $avatarQueuePreference;

    /** Tamaño máximo de archivo (bytes). */
    // Constante con el valor por defecto para maxBytes (15 MB).
    public const MAX_BYTES = 15 * 1024 * 1024; // 10 MB

    /** Límite de megapíxeles (ancho*alto / 1e6). */
    // Constante con el valor por defecto para maxMegapixels (24 MP).
    public const MAX_MEGAPIXELS = 24; // 24 MP (p.ej., 6000x4000)

    /** Dimensiones mínimas y máximas permitidas. */
    // Constantes con valores por defecto para dimensiones mínimas y máximas.
    public const MIN_WIDTH  = 64;
    public const MIN_HEIGHT = 64;
    public const MAX_WIDTH  = 8192; // ajusta si quieres 16384
    public const MAX_HEIGHT = 8192;

    /** Extensiones permitidas (por defecto). */
    // Constante con la lista de extensiones permitidas por defecto.
    public const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'avif',
        'gif',
    ];

    /** Tipos MIME permitidos → extensión por defecto. */
    // Constante con el mapa MIME => extensión por defecto.
    public const ALLOWED_MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif'  => 'gif',
    ];

    // Constante para la calidad por defecto de WebP.
    public const WEBP_QUALITY = 82;

    /**
     * (Opcional) tamaños de conversión estándar para avatares.
     * Útiles si luego quieres leerlos desde tu AvatarConversionProfile.
     */
    // Constantes para tamaños comunes de avatares.
    public const THUMB_WIDTH  = 160;
    public const THUMB_HEIGHT = 160;

    public const MEDIUM_WIDTH  = 512;
    public const MEDIUM_HEIGHT = 512;

    public const LARGE_WIDTH  = 1024;
    public const LARGE_HEIGHT = 1024;

    // Valor por defecto para la preferencia de encolado.
    public const QUEUE_CONVERSIONS_DEFAULT = true;

    /**
     * Constructor de FileConstraints.
     *
     * Inicializa las propiedades con valores de configuración o valores por defecto,
     * aplicando clamps defensivos para asegurar que los valores estén dentro de rangos razonables.
     *
     * @param  int|null   $maxBytes       Tamaño máximo del archivo en bytes (sobreescribe config).
     * @param  int|null   $minDimension   Dimensión mínima permitida en píxeles (sobreescribe config).
     * @param  int|null   $maxDimension   Dimensión máxima permitida en píxeles (sobreescribe config).
     * @param  float|null $maxMegapixels  Límite máximo de megapíxeles (sobreescribe config).
     */
    public function __construct(
        ?int $maxBytes = null,
        ?int $minDimension = null,
        ?int $maxDimension = null,
        ?float $maxMegapixels = null,
    ) {
        // Inicializa las propiedades leyendo la configuración, aplicando overrides y límites de seguridad.
        // cfgInt/Float leen la clave de config, aplican el override si es necesario, y limitan el valor entre min y max.
        $config = config('image-pipeline', []);
        if (!is_array($config)) {
            Log::error('file_constraints.config_invalid', [
                'received_type' => gettype($config),
            ]);

            throw new InvalidArgumentException('Configuration "image-pipeline" must be an array.');
        }

        $this->maxBytes      = $this->extractInt($config, 'max_bytes', $maxBytes, 1, 50 * 1024 * 1024, self::MAX_BYTES);
        $this->minDimension  = $this->extractInt($config, 'min_dimension', $minDimension, 16, 16384, self::MIN_WIDTH);
        $this->maxDimension  = $this->extractInt($config, 'max_edge', $maxDimension, $this->minDimension, 16384, self::MAX_WIDTH);
        $this->maxMegapixels = $this->extractFloat($config, 'max_megapixels', $maxMegapixels, 0.1, 100.0, self::MAX_MEGAPIXELS);

        // cfgExtensions y cfgMimeMap leen y limpian listas de extensiones y mimes desde la config.
        $this->allowedExtensions = $this->cfgExtensions('image-pipeline.allowed_extensions', self::ALLOWED_EXTENSIONS);
        $this->allowedMimeMap    = $this->cfgMimeMap('image-pipeline.allowed_mimes', self::ALLOWED_MIME_MAP);
        // Las MIME types permitidas son simplemente las claves del mapa MIME => extensión.
        $this->allowedMimeTypes  = array_keys($this->allowedMimeMap);

        // cfgBool lee un valor booleano de la config, interpretando strings como "true", "1", etc.
        $this->queueConversionsDefault = $this->cfgBool(
            'image-pipeline.queue_conversions_default',
            self::QUEUE_CONVERSIONS_DEFAULT
        );

        // resolveQueuePreference interpreta un valor de configuración (string/bool) y lo convierte a bool o null.
        $this->avatarQueuePreference = $this->resolveQueuePreference(
            config('image-pipeline.avatar_queue_conversions')
        );
    }

    /**
     * Devuelve las extensiones permitidas.
     *
     * @return array<int,string>
     */
    public function allowedExtensions(): array
    {
        return $this->allowedExtensions;
    }

    /**
     * Devuelve la lista de mimes permitidos.
     *
     * @return array<int,string>
     */
    public function allowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }

    /**
     * Devuelve el mapa MIME => extensión recomendada.
     *
     * @return array<string,string>
     */
    public function allowedMimeMap(): array
    {
        return $this->allowedMimeMap;
    }

    /**
     * Devuelve la preferencia global de cola (fallback).
     */
    public function queueConversionsDefault(): bool
    {
        return $this->queueConversionsDefault;
    }

    /**
     * Devuelve la preferencia de cola para conversions de avatar.
     * Si avatarQueuePreference es null, usa el valor por defecto global.
     */
    public function queueConversionsForAvatar(): bool
    {
        return $this->avatarQueuePreference ?? $this->queueConversionsDefault;
    }

    /**
     * Valida un UploadedFile a nivel de constraints básicos (peso y MIME).
     * NO abre la imagen. Úsalo en FormRequest o antes de pipelines pesados.
     *
     * Este método verifica que el archivo subido sea válido, que su tamaño no exceda el límite
     * y que su tipo MIME real (detectado con `finfo`) esté en la lista de permitidos.
     * Utiliza mensajes de error localizados (__()).
     *
     * @throws InvalidArgumentException  Si el archivo no es válido, tiene tamaño incorrecto o no se puede leer.
     */
    public function validateUploadedFile(UploadedFile $file): void
    {
        // Verifica que el archivo subido haya pasado la validación de PHP (p. ej., no hubo errores de subida)
        if (!$file->isValid()) {
            throw new InvalidArgumentException(__('file-constraints.upload.invalid_file'));
        }

        // Obtiene la ruta real al archivo temporal en el servidor
        $realPath = $file->getRealPath();
        // Verifica que la ruta exista y sea legible
        if (!$realPath || !is_readable($realPath)) {
            throw new InvalidArgumentException(__('file-constraints.upload.unreadable_temp'));
        }

        // Crea un objeto finfo para detectar el tipo MIME real del archivo basado en sus bytes (magic bytes)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new InvalidArgumentException(__('file-constraints.upload.mime_detection_failed'));
        }

        try {
            // Lee el tipo MIME del archivo en disco
            $realMime = finfo_file($finfo, $realPath) ?: null;
        } finally {
            // Es importante cerrar el recurso finfo para liberar memoria
            finfo_close($finfo);
        }

        // Verifica que el tipo MIME detectado esté en la lista de permitidos
        if ($realMime === null || !in_array($realMime, $this->allowedMimeTypes, true)) {
            throw new InvalidArgumentException(
                __('file-constraints.upload.mime_not_allowed', [
                    'mime'    => $realMime ?? __('file-constraints.upload.unknown_mime'), // Si no se detectó MIME, usa un texto genérico
                    'allowed' => implode(', ', $this->allowedMimeTypes), // Lista los tipos permitidos
                ])
            );
        }

        // Verifica que el tamaño del archivo esté dentro del límite configurado
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw new InvalidArgumentException(
                __('file-constraints.upload.size_exceeded', ['max' => $this->maxBytes])
            );
        }
    }

    /**
     * Valida dimensiones (min/max) y megapíxeles.
     * Recibe el ancho y alto de una imagen y comprueba que cumplan con los límites establecidos.
     * Utiliza mensajes de error localizados (__()).
     *
     * @throws InvalidArgumentException  Si las dimensiones no cumplen con los límites configurados.
     */
    public function assertDimensions(int $width, int $height): void
    {
        // Comprueba si alguna dimensión es menor que el mínimo permitido
        if ($width < $this->minDimension || $height < $this->minDimension) {
            throw new InvalidArgumentException(
                __('file-constraints.dimensions.too_small', ['min' => $this->minDimension])
            );
        }

        // Comprueba si alguna dimensión excede el máximo permitido
        if ($width > $this->maxDimension || $height > $this->maxDimension) {
            throw new InvalidArgumentException(
                __('file-constraints.dimensions.too_large', ['max' => $this->maxDimension])
            );
        }

        // Calcula los megapíxeles (ancho * alto / 1,000,000) y lo compara con el límite
        $mp = ($width * $height) / 1_000_000;
        if ($mp > $this->maxMegapixels) {
            throw new InvalidArgumentException(
                __('file-constraints.dimensions.megapixels_exceeded', ['max' => $this->maxMegapixels])
            );
        }
    }

    /**
     * Obtiene dimensiones rápidas usando getimagesize() (barato) y valida.
     * Este método no decodifica la imagen con GD/Imagick (como hace Intervention Image),
     * solo lee los metadatos iniciales del archivo. Es útil para una verificación previa rápida.
     * Utiliza mensajes de error localizados (__()).
     *
     * @return array{0:int,1:int} [width, height] Devuelve un array con el ancho y alto.
     *
     * @throws InvalidArgumentException Si no se pueden detectar dimensiones o no cumplen los límites.
     */
    public function probeAndAssert(UploadedFile $file): array
    {
        // Primero, realiza una validación básica de tipo, tamaño y MIME real
        $this->validateUploadedFile($file);

        $path = $file->getRealPath();

        // set_error_handler captura los errores de PHP (como los que puede lanzar getimagesize)
        // y los convierte en una excepción InvalidArgumentException con un mensaje localizado.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new InvalidArgumentException(
                __('file-constraints.probe.read_error', ['error' => $errstr])
            );
        });

        try {
            // getimagesize() es una función rápida para leer dimensiones y metadatos sin decodificar toda la imagen
            $info = getimagesize($path);
        } finally {
            // restaura el manejador de errores original
            restore_error_handler();
        }

        // Verifica que getimagesize haya devuelto información válida (ancho y alto positivos)
        if (!$info || !isset($info[0], $info[1]) || $info[0] <= 0 || $info[1] <= 0) {
            throw new InvalidArgumentException(__('file-constraints.probe.invalid_dimensions'));
        }

        // Validar contra image bombs (descompresión desproporcionada)
        // Calcula un tamaño "descomprimido" estimado. Si es mucho mayor que el archivo real,
        // puede ser un intento de "image bomb" (descomprimir muchísimo con poco archivo).
        // $info['bits'] puede no estar presente, por lo que se usa ?? 8 como valor por defecto.
        $rawSize  = $info[0] * $info[1] * ($info['bits'] ?? 8) / 8; // bits por pixel
        $fileSize = $file->getSize() ?? 0;
        if ($fileSize > 0 && ($rawSize / $fileSize) > 100) { // Umbral de 100
            throw new InvalidArgumentException(__('file-constraints.probe.image_bomb'));
        }

        // Finalmente, verifica que las dimensiones obtenidas cumplan con los límites
        $this->assertDimensions($info[0], $info[1]);
        // Devuelve el ancho y alto como un array
        return [$info[0], $info[1]];
    }

    /**
     * Helper para decidir si conviene redimensionar según maxDimension.
     * Devuelve true si alguno de los bordes (ancho o alto) es mayor que el máximo permitido.
     */
    public function needsResize(int $width, int $height): bool
    {
        return max($width, $height) > $this->maxDimension;
    }

    /**
     * Calcula nuevas dimensiones manteniendo proporción para encajar en maxDimension.
     * Por ejemplo, si una imagen es 4000x2000 y maxDimension es 2000, devolverá 2000x1000.
     *
     * @return array{0:int,1:int} [newWidth, newHeight] Devuelve un array con las nuevas dimensiones.
     */
    public function resizedToFit(int $width, int $height): array
    {
        // Encuentra la dimensión más grande (ancho o alto)
        $maxEdge = max($width, $height);
        // Si la dimensión más grande ya está dentro del límite, no se redimensiona
        if ($maxEdge <= $this->maxDimension) {
            return [$width, $height];
        }

        // Calcula el factor de escala necesario para que la dimensión más grande encaje
        $scale = $this->maxDimension / $maxEdge;
        // Calcula las nuevas dimensiones manteniendo la proporción, asegurando que sean al menos 1px
        $newW  = max(1, (int) floor($width * $scale));
        $newH  = max(1, (int) floor($height * $scale));

        return [$newW, $newH];
    }

    /**
     * Resuelve la preferencia de cola permitiendo strings tipo "true"/"false".
     * Convierte un valor de configuración que puede ser string o bool en un booleano o null.
     */
    private function resolveQueuePreference(mixed $value): ?bool
    {
        // Si ya es booleano, devuélvelo
        if (is_bool($value)) {
            return $value;
        }

        // Si es un string, normalízalo (minúsculas, sin espacios) y compáralo con valores comunes
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'queue', 'queued'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'sync', 'inline', 'nonqueued', 'non_queued'], true)) {
                return false;
            }
        }

        // Si es un número, 1 es true, cualquier otro valor es false
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        // Si no se puede interpretar, devuelve null
        return null;
    }

    /**
     * Lee un valor entero de la configuración con clamps defensivos.
     * Asegura que el valor esté entre $min y $max. Si no está en config, usa un valor por defecto.
     */
    private function extractInt(array $config, string $key, ?int $override, int $min, int $max, int $default): int
    {
        if (is_int($override)) {
            return max($min, min($max, $override));
        }

        $value = data_get($config, $key);
        if (is_numeric($value)) {
            return max($min, min($max, (int) $value));
        }

        return max($min, min($max, $default));
    }

    /**
     * Lee un valor flotante de la configuración con clamps defensivos.
     * Asegura que el valor esté entre $min y $max. Si no está en config, usa un valor por defecto.
     */
    private function extractFloat(array $config, string $key, ?float $override, float $min, float $max, float $default): float
    {
        if (is_float($override)) {
            return max($min, min($max, $override));
        }

        $value = data_get($config, $key);
        if (is_numeric($value)) {
            return max($min, min($max, (float) $value));
        }

        return max($min, min($max, $default));
    }

    /**
     * Lee una lista de extensiones desde config aplicando saneado básico.
     * Filtra entradas no válidas (no string, vacío) y devuelve un array limpio o un valor por defecto.
     * Elimina duplicados.
     *
     * @param  string              $key      Clave de la configuración (p. ej., 'image-pipeline.allowed_extensions').
     * @param  array<int,string>   $defaults Valor por defecto si la configuración no es válida o está vacía.
     * @return array<int,string>   Lista de extensiones saneadas.
     */
    private function cfgExtensions(string $key, array $defaults): array
    {
        // Lee el valor de la configuración
        $value = config($key);
        // Si no es un array, devuelve los valores por defecto
        if (!is_array($value)) {
            return $defaults;
        }

        $filtered = [];
        // Itera sobre cada valor en el array de la configuración
        foreach ($value as $ext) {
            // Solo procesa cadenas
            if (!is_string($ext)) {
                continue;
            }
            // Limpia espacios y convierte a minúsculas
            $ext = strtolower(trim($ext));
            // Ignora cadenas vacías
            if ($ext === '') {
                continue;
            }
            $filtered[] = $ext;
        }

        // Si el array resultante no está vacío, lo devuelve sin duplicados. Si está vacío, devuelve los valores por defecto.
        return $filtered !== [] ? array_values(array_unique($filtered)) : $defaults;
    }

    /**
     * Lee un mapa mime => extensión desde config aplicando saneado básico.
     * Filtra entradas no válidas (clave o valor no string, vacío) y devuelve un array limpio o un valor por defecto.
     *
     * @param  string                    $key      Clave de la configuración (p. ej., 'image-pipeline.allowed_mimes').
     * @param  array<string,string>      $defaults Valor por defecto si la configuración no es válida o está vacía.
     * @return array<string,string>      Mapa MIME => extensión saneado.
     */
    private function cfgMimeMap(string $key, array $defaults): array
    {
        // Lee el valor de la configuración
        $value = config($key);
        // Si no es un array, devuelve los valores por defecto
        if (!is_array($value)) {
            return $defaults;
        }

        $filtered = [];
        // Itera sobre cada par clave-valor en el array de la configuración
        foreach ($value as $mime => $ext) {
            // Solo procesa si ambos son cadenas
            if (!is_string($mime) || !is_string($ext)) {
                continue;
            }
            // Limpia espacios y convierte a minúsculas
            $mime = strtolower(trim($mime));
            $ext  = strtolower(trim($ext));
            // Ignora claves o valores vacíos
            if ($mime === '' || $ext === '') {
                continue;
            }
            $filtered[$mime] = $ext;
        }

        // Si el array resultante no está vacío, lo devuelve. Si está vacío, devuelve los valores por defecto.
        return $filtered !== [] ? $filtered : $defaults;
    }

    /**
     * Lee un booleano de config interpretando strings y enteros comunes.
     * Convierte cadenas como "true", "1", "yes", "on" en `true` y "false", "0", "no", "off" en `false`.
     */
    private function cfgBool(string $key, bool $default): bool
    {
        // Lee el valor de la configuración
        $value = config($key);
        // Si ya es booleano, devuélvelo
        if (is_bool($value)) {
            return $value;
        }
        // Si es un string, compáralo con valores comunes
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        // Si es un número, 1 es true, cualquier otro es false
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        // Si no se puede interpretar, devuelve el valor por defecto
        return $default;
    }
}
