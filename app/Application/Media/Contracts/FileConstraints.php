<?php

declare(strict_types=1);

namespace App\Application\Media\Contracts;

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
    // Propiedades readonly que contienen los valores configurados
    public readonly int $maxBytes;                 // Tamaño máximo permitido en bytes
    public readonly int $minDimension;             // Dimensión mínima permitida en píxeles
    public readonly int $maxDimension;             // Dimensión máxima permitida en píxeles
    public readonly float $maxMegapixels;          // Límite máximo de megapíxeles
    public readonly array $allowedExtensions;      // Extensiones permitidas
    public readonly array $allowedMimeTypes;       // Tipos MIME permitidos
    public readonly array $allowedMimeMap;         // Mapa de MIME a extensión
    private readonly array $allowedMimeLookup;     // Mapa para verificación rápida de MIME
    public readonly bool $queueConversionsDefault; // Valor por defecto para colas de conversiones
    public readonly ?bool $avatarQueuePreference;  // Preferencia específica para avatares
    public readonly bool $enforceStrictMagicBytes; // Habilita validación estricta de firmas/magic bytes
    public readonly array $allowedMagicSignatures; // Mapa de firmas binarias permitidas (hex => tipo esperado)
    public readonly bool $preventPolyglotFiles;    // Rechaza cabeceras mixtas (polyglot) en binarios
    public readonly ?float $maxDecompressionRatio; // Ratio máximo bytes descomprimidos/bytes en disco (anti-bomb)

    // Constantes con valores por defecto
    public const MAX_BYTES = 25 * 1024 * 1024;    // 25MB
    public const MAX_MEGAPIXELS = 48.0;           // 48 megapíxeles
    public const MIN_WIDTH  = 64;                 // 64px ancho mínimo
    public const MIN_HEIGHT = 64;                 // 64px alto mínimo
    public const MAX_WIDTH  = 8192;               // 8192px ancho máximo
    public const MAX_HEIGHT = 8192;               // 8192px alto máximo

    public const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'avif',
        'gif',
    ];

    public const ALLOWED_MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif'  => 'gif',
    ];

    public const WEBP_QUALITY = 82;               // Calidad por defecto para WebP
    public const THUMB_WIDTH  = 160;              // Ancho por defecto para miniaturas
    public const THUMB_HEIGHT = 160;              // Alto por defecto para miniaturas
    public const MEDIUM_WIDTH  = 512;             // Ancho por defecto para imágenes medianas
    public const MEDIUM_HEIGHT = 512;             // Alto por defecto para imágenes medianas
    public const LARGE_WIDTH  = 1024;             // Ancho por defecto para imágenes grandes
    public const LARGE_HEIGHT = 1024;             // Alto por defecto para imágenes grandes

    public const QUEUE_CONVERSIONS_DEFAULT = true; // Cola conversiones por defecto

    // Firmas binarias por defecto para validación (hex => etiqueta)
    private const MAGIC_DEFAULT = [
        'ffd8ff' => 'image/jpeg', // JPEG
        '89504e470d0a1a0a' => 'image/png', // PNG
        '47494638' => 'image/gif', // GIF87a/GIF89a
        '000000206674797061766966' => 'image/avif', // AVIF (ftypavif, 32-byte box)
        '0000001c6674797061766966' => 'image/avif', // AVIF (ftypavif, 28-byte box)
        '52494646' => 'riff', // RIFF container (WEBP/others)
        '57454250' => 'image/webp', // WEBP chunk marker
        '25504446' => 'application/pdf', // PDF
        '504b0304' => 'zip', // ZIP/OOXML
    ];

    /**
     * Constructor del objeto de restricciones de archivo.
     * 
     * @param int|null $maxBytes Tamaño máximo en bytes (override opcional)
     * @param int|null $minDimension Dimensión mínima en píxeles (override opcional)
     * @param int|null $maxDimension Dimensión máxima en píxeles (override opcional)
     * @param float|null $maxMegapixels Límite de megapíxeles (override opcional)
     */
    public function __construct(
        ?int $maxBytes = null,
        ?int $minDimension = null,
        ?int $maxDimension = null,
        ?float $maxMegapixels = null,
    ) {
        $config = config('image-pipeline', []);
        if (!is_array($config)) {
            Log::error('file_constraints.config_invalid', [
                'received_type' => gettype($config),
            ]);

            throw new InvalidArgumentException('Configuration "image-pipeline" must be an array.');
        }

        $this->maxBytes = $this->extractInt($config, 'max_bytes', $maxBytes, 1, 50 * 1024 * 1024, self::MAX_BYTES);
        $this->minDimension = $this->extractInt($config, 'min_dimension', $minDimension, 16, 16384, self::MIN_WIDTH);
        $this->maxDimension = $this->extractInt($config, 'max_edge', $maxDimension, $this->minDimension, 16384, self::MAX_WIDTH);
        $this->maxMegapixels = $this->extractFloat($config, 'max_megapixels', $maxMegapixels, 0.1, 100.0, self::MAX_MEGAPIXELS);

        $this->allowedExtensions = $this->cfgExtensions(
            data_get($config, 'allowed_extensions'),
            self::ALLOWED_EXTENSIONS
        );
        $this->allowedMimeMap = $this->cfgMimeMap(
            data_get($config, 'allowed_mimes'),
            self::ALLOWED_MIME_MAP,
            $this->allowedExtensions
        );
        $this->allowedMimeTypes = array_keys($this->allowedMimeMap);
        $this->allowedMimeLookup = array_fill_keys($this->allowedMimeTypes, true);

        $this->queueConversionsDefault = $this->cfgBool(
            data_get($config, 'queue_conversions_default'),
            self::QUEUE_CONVERSIONS_DEFAULT
        );
        $this->avatarQueuePreference = $this->resolveQueuePreference(
            data_get($config, 'avatar_queue_conversions')
        );
        $this->enforceStrictMagicBytes = $this->cfgBool(
            data_get($config, 'enforce_strict_magic_bytes'),
            false
        );
        $this->allowedMagicSignatures = $this->cfgMagicSignatures(
            data_get($config, 'allowed_magic_signatures'),
            self::MAGIC_DEFAULT
        );
        $this->preventPolyglotFiles = $this->cfgBool(
            data_get($config, 'prevent_polyglot_files'),
            true
        );
        $this->maxDecompressionRatio = $this->cfgFloatNullable(
            data_get($config, 'max_decompression_ratio'),
            null,
            1.0,
            10_000.0
        );
    }

    /**
     * Obtiene las extensiones permitidas.
     *
     * @return array Extensiones permitidas
     */
    public function allowedExtensions(): array
    {
        return $this->allowedExtensions;
    }

    /**
     * Obtiene los tipos MIME permitidos.
     *
     * @return array Tipos MIME permitidos
     */
    public function allowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }

    /**
     * Obtiene el mapa de MIME a extensión.
     *
     * @return array Mapa de MIME a extensión
     */
    public function allowedMimeMap(): array
    {
        return $this->allowedMimeMap;
    }

    /**
     * Verifica si un tipo MIME está permitido.
     *
     * @param string $mime Tipo MIME a verificar
     * @return bool True si está permitido, false en caso contrario
     */
    public function isMimeAllowed(string $mime): bool
    {
        return isset($this->allowedMimeLookup[$mime]);
    }

    /**
     * Obtiene el valor por defecto para colas de conversiones.
     *
     * @return bool Valor por defecto para colas de conversiones
     */
    public function queueConversionsDefault(): bool
    {
        return $this->queueConversionsDefault;
    }

    /**
     * Obtiene la preferencia de colas para conversiones de avatar.
     *
     * @return bool True si se deben usar colas para avatares
     */
    public function queueConversionsForAvatar(): bool
    {
        return $this->avatarQueuePreference ?? $this->queueConversionsDefault;
    }

    /**
     * Verifica si un archivo subido cumple con las restricciones.
     *
     * @param UploadedFile $file Archivo subido a verificar
     * @throws InvalidArgumentException Si el archivo no cumple con las restricciones
     */
    public function assertValidUpload(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException(__('file-constraints.invalid_file'));
        }

        $size = $file->getSize();
        if ($size !== false && $size > $this->maxBytes) {
            throw new InvalidArgumentException(__('file-constraints.too_heavy', [
                'max' => $this->maxBytes,
            ]));
        }

        $mime = (string) $file->getMimeType();
        if (!$this->isMimeAllowed($mime)) {
            throw new InvalidArgumentException(__('file-constraints.mime_not_allowed', [
                'mime' => $mime,
            ]));
        }
    }

    /**
     * Extrae un valor entero de la configuración.
     *
     * @param array $config Configuración
     * @param string $key Clave a buscar
     * @param int|null $override Valor de override
     * @param int $min Valor mínimo
     * @param int $max Valor máximo
     * @param int $default Valor por defecto
     * @return int Valor extraído y validado
     */
    private function extractInt(array $config, string $key, ?int $override, int $min, int $max, int $default): int
    {
        $value = $override ?? ($config[$key] ?? null);
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            return $default;
        }

        $clamped = max($min, min($max, $int));
        if ($clamped !== $int) {
            Log::warning('file_constraints.clamped_int', [
                'key' => $key,
                'provided' => $int,
                'applied' => $clamped,
                'min' => $min,
                'max' => $max,
            ]);
        }

        return $clamped;
    }

    /**
     * Extrae un valor flotante de la configuración.
     *
     * @param array $config Configuración
     * @param string $key Clave a buscar
     * @param float|null $override Valor de override
     * @param float $min Valor mínimo
     * @param float $max Valor máximo
     * @param float $default Valor por defecto
     * @return float Valor extraído y validado
     */
    private function extractFloat(array $config, string $key, ?float $override, float $min, float $max, float $default): float
    {
        $value = $override ?? ($config[$key] ?? null);
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false) {
            return $default;
        }

        $clamped = max($min, min($max, $float));
        if ($clamped !== $float) {
            Log::warning('file_constraints.clamped_float', [
                'key' => $key,
                'provided' => $float,
                'applied' => $clamped,
                'min' => $min,
                'max' => $max,
            ]);
        }

        return $clamped;
    }

    /**
     * Configura las extensiones permitidas.
     *
     * @param mixed $value Valor de configuración
     * @param array $fallback Valores por defecto
     * @return array Extensiones configuradas
     */
    private function cfgExtensions(mixed $value, array $fallback): array
    {
        $exts = is_array($value)
            ? array_filter(array_map(static fn($v) => strtolower(trim((string) $v)), $value))
            : $fallback;
        $exts = array_values(array_unique($exts));

        if ($exts === []) {
            Log::warning('file_constraints.extensions_empty', ['fallback_applied' => $fallback]);
            return $fallback;
        }

        return $exts;
    }

    /**
     * Configura el mapa de MIME a extensión.
     *
     * @param mixed $value Valor de configuración
     * @param array $fallback Valores por defecto
     * @param array $allowedExtensions Extensiones permitidas para validación
     * @return array Mapa configurado
     */
    private function cfgMimeMap(mixed $value, array $fallback, array $allowedExtensions): array
    {
        if (!is_array($value) || $value === []) {
            return $fallback;
        }

        $map = [];
        foreach ($value as $mime => $ext) {
            if (!is_string($mime) || $mime === '') {
                continue;
            }
            $normalizedExt = is_string($ext) ? strtolower(trim($ext)) : $fallback[$mime] ?? null;
            if ($normalizedExt !== null && !in_array($normalizedExt, $allowedExtensions, true)) {
                Log::warning('file_constraints.mime_extension_mismatch', [
                    'mime' => $mime,
                    'extension' => $normalizedExt,
                ]);
                continue;
            }

            $map[strtolower(trim($mime))] = $normalizedExt;
        }

        return array_filter($map, static fn($ext) => $ext !== null && $ext !== '');
    }

    /**
     * Configura firmas mágicas permitidas.
     *
     * @param mixed $value Lista/mapa de firmas hexadecimales
     * @param array $fallback Valores por defecto
     * @return array<string,string> Mapa hex => etiqueta
     */
    private function cfgMagicSignatures(mixed $value, array $fallback): array
    {
        if (!is_array($value) || $value === []) {
            return $fallback;
        }

        $normalized = [];
        foreach ($value as $hex => $label) {
            $key = is_int($hex) ? (string) $label : (string) $hex;
            $val = is_int($hex) ? (string) $hex : (string) $label;

            $key = strtolower(trim($key));
            $val = trim($val);

            if ($key === '' || preg_match('/[^0-9a-f]/', $key) === 1) {
                continue;
            }

            // Las firmas deben tener longitud par (bytes completos) y al menos 4 hex (2 bytes) para evitar falsos positivos.
            if ((strlen($key) % 2) !== 0 || strlen($key) < 4) {
                Log::warning('file_constraints.magic_signature_too_short', [
                    'signature' => $key,
                ]);
                continue;
            }

            $normalized[$key] = $val === '' ? 'unknown' : $val;
        }

        return $normalized !== [] ? $normalized : $fallback;
    }

    /**
     * Configura un valor booleano.
     *
     * @param mixed $value Valor de configuración
     * @param bool $default Valor por defecto
     * @return bool Valor configurado
     */
    private function cfgBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enable', 'enabled'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled', 'none', 'null'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Resuelve la preferencia de colas para conversiones.
     *
     * @param mixed $value Valor de configuración
     * @return bool|null Valor resuelto o null si no está configurado
     */
    private function resolveQueuePreference(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return $this->cfgBool($value, self::QUEUE_CONVERSIONS_DEFAULT);
    }

    /**
     * Extrae un valor flotante opcional.
     *
     * @param mixed $value Valor de configuración
     * @param float|null $default Valor por defecto
     * @param float $min Valor mínimo aceptable
     * @param float $max Valor máximo aceptable
     * @return float|null Valor configurado o null si no está disponible
     */
    private function cfgFloatNullable(mixed $value, ?float $default, float $min, float $max): ?float
    {
        if ($value === null) {
            return $default;
        }

        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false) {
            return $default;
        }

        return max($min, min($max, $float));
    }

    /**
     * Valida tamaño, MIME y dimensiones del archivo y devuelve dimensiones confiables.
     *
     * @param UploadedFile $file Archivo subido a validar
     * @return array{0:int,1:int} [width,height] Dimensiones del archivo
     * @throws InvalidArgumentException Si el archivo no cumple con las restricciones
     */
    public function probeAndAssert(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException(__('file-constraints.invalid_file'));
        }

        $size = $file->getSize();
        if ($size !== false && $size > $this->maxBytes) {
            throw new InvalidArgumentException(__('file-constraints.too_heavy', ['max' => $this->maxBytes]));
        }

        $mime = (string) $file->getMimeType();
        if (!$this->isMimeAllowed($mime)) {
            throw new InvalidArgumentException(__('file-constraints.mime_not_allowed', ['mime' => $mime]));
        }

        $path = $file->getRealPath();
        if ($path === false || $path === null) {
            throw new InvalidArgumentException(__('file-constraints.file_not_readable'));
        }

        $info = @getimagesize($path);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            throw new InvalidArgumentException(__('file-constraints.invalid_image'));
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width < $this->minDimension || $height < $this->minDimension) {
            throw new InvalidArgumentException(__('file-constraints.dimensions_too_small', [
                'min' => $this->minDimension,
            ]));
        }

        if ($width > $this->maxDimension || $height > $this->maxDimension) {
            throw new InvalidArgumentException(__('file-constraints.dimensions_too_large', [
                'max' => $this->maxDimension,
            ]));
        }

        $megapixels = ($width * $height) / 1_000_000;
        if ($megapixels > $this->maxMegapixels) {
            throw new InvalidArgumentException(__('file-constraints.megapixels_exceeded', [
                'max' => $this->maxMegapixels,
            ]));
        }

        return [$width, $height];
    }
}
