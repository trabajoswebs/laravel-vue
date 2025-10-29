<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use RuntimeException;
use SplFileObject;
use Throwable;
use App\Support\Media\ConversionProfiles\FileConstraints;

/**
 * Regla de validación endurecida para archivos de imagen.
 *
 * Esta regla:
 *  - Valida firma/MIME real con `finfo`, extensión y tamaño.
 *  - Intenta decodificar la imagen con Intervention (detección temprana de corrupciones).
 *  - Comprueba dimensiones máximas (ancho/alto).
 *  - Escanea los primeros bytes del archivo buscando payloads sospechosos (p. ej. `<?php`, `eval(`).
 *
 * @example
 *  use App\Rules\SecureImageValidation;
 *  // En un FormRequest:
 *  public function rules(): array {
 *      return [
 *          'avatar' => ['required', 'file', new SecureImageValidation()],
 *      ];
 *  }
 *
 * @security
 *  - No confíes solo en la extensión; por eso se verifica MIME vía `finfo` y se decodifica la imagen.
 *  - Mantén esta validación en combinación con políticas de subida (disco aislado, desactivar ejecución en el storage, etc.).
 *
 * @see https://www.php.net/manual/en/function.finfo-file.php     Detección MIME
 * @see https://image.intervention.io/     Uso de Intervention Image
 */
class SecureImageValidation implements ValidationRule, DataAwareRule
{
    /**
     * Número de bytes iniciales a escanear para detectar payloads sospechosos.
     *
     * @var int
     */
    private const SCAN_BYTES = 50 * 1024;

    /**
     * Umbral de ratio de descompresión para detectar "image bombs".
     * Si (ancho*alto*bits/8) / size_bytes > RATIO → sospechoso.
     */
    private const BOMB_RATIO_THRESHOLD = 100;

    /**
     * Datos del contexto de validación suministrados por el validador.
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Cached decoded image instance when available.
     *
     * @var object|null
     */
    private ?object $decodedImage = null;

    /**
     * Cached dimensions detected via lightweight parsing.
     *
     * @var array{0:int,1:int}|null
     */
    private ?array $detectedDimensions = null;

    /**
     * Constraints compartidos (SSOT).
     */
    private FileConstraints $constraints;

    /**
     * Callable responsible for decoding the image path.
     *
     * @var \Closure(string):object
     */
    private \Closure $decodeImage;

    /**
     * Tamaño máximo en bytes permitido para este contexto.
     */
    private int $maxFileSizeBytes;

    /** Normalización opcional vía re-encode para mitigar polyglots/metadata. */
    private bool $enableNormalization = false;

    /** Umbral configurable para detectar image bombs. */
    private int $bombRatioThreshold = self::BOMB_RATIO_THRESHOLD;

    /**
     * Initialize the rule and allow dependency injection of the decoder and size limit.
     *
     * @param callable|null        $decodeImage        Callable to decode the image file. If null, uses default Intervention decoder.
     * @param int|null             $maxFileSizeBytes   Max file size in bytes. If null, uses configurado en FileConstraints.
     * @param bool|null            $normalize          Habilita normalización (re-encode) adicional.
     * @param int|null             $bombRatioThreshold Umbral personalizado para detectar image bombs.
     * @param FileConstraints|null $constraints        Conjunto de límites compartido (SSOT).
     */
    public function __construct(
        ?callable $decodeImage = null,
        ?int $maxFileSizeBytes = null,
        ?bool $normalize = null,
        ?int $bombRatioThreshold = null,
        ?FileConstraints $constraints = null,
    ) {
        $this->constraints = $constraints ?? app(FileConstraints::class);

        $this->decodeImage = $decodeImage instanceof \Closure
            ? $decodeImage
            : $this->makeDefaultDecoder($decodeImage);

        // Usa límite desde configuración cuando no se pasa explícito (evita hardcodear).
        $this->maxFileSizeBytes = $maxFileSizeBytes
            ?? $this->constraints->maxBytes;

        // Optional hardening knobs
        $normalizationEnabled = config('image-pipeline.normalization.enabled', false);
        $this->enableNormalization = (bool) ($normalize ?? $normalizationEnabled);
        $configRatio = (int) config('image-pipeline.bomb_ratio_threshold', self::BOMB_RATIO_THRESHOLD);
        if ($configRatio <= 0) {
            $configRatio = self::BOMB_RATIO_THRESHOLD;
        }
        if ($bombRatioThreshold !== null && $bombRatioThreshold > 0) {
            $this->bombRatioThreshold = $bombRatioThreshold;
        } else {
            $this->bombRatioThreshold = $configRatio;
        }
    }

    /**
     * Inyecta el contexto de datos del validador (p. ej., user_id, otros campos del request).
     *
     * @param array<string, mixed> $data
     * @return static
     *
     * @inheritDoc
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Create a default decoder for Intervention Image v3 only.
     *
     * @param callable|null $decoder Optional custom decoder.
     * @return \Closure(string):object
     */
    private function makeDefaultDecoder(?callable $decoder = null): \Closure
    {
        if ($decoder !== null) {
            return static fn(string $path): object => $decoder($path);
        }

        return static function (string $path): object {
            static $manager;

            if ($manager === null) {
                $manager = self::resolveImageManager();
            }

            if (is_callable($manager)) {
                $image = $manager($path);
            } elseif (is_object($manager) && method_exists($manager, 'read')) {
                $image = $manager->read($path);
            } else {
                throw new RuntimeException('Unable to decode image: unsupported manager implementation.');
            }

            if (! is_object($image)) {
                throw new RuntimeException('Unable to decode image: decoder did not return an object.');
            }

            return $image;
        };
    }

    private static function applyGdMemoryLimit(): void
    {
        $gdMb = (int) config('image-pipeline.resource_limits.gd_memory_mb', 0);

        if ($gdMb > 0) {
            @ini_set('memory_limit', max(64, $gdMb) . 'M');
        }
    }

    /**
     * Resolve a suitable Intervention Image manager instance or callable.
     *
     * @return object|callable
     */
    private static function resolveImageManager(): object|callable
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }

        // Intervention Image v3 (Interface + container)
        if (interface_exists('Intervention\\Image\\Interfaces\\ImageManagerInterface')) {
            if (function_exists('app')) {
                try {
                    return $cached = app('Intervention\\Image\\Interfaces\\ImageManagerInterface');
                } catch (Throwable) {
                    // caer a instanciación directa
                }
            }

            // Instanciar v3 directamente con driver disponible
            if (class_exists('Intervention\\Image\\Drivers\\Imagick\\Driver')) {
                $driver = new \Intervention\Image\Drivers\Imagick\Driver();
                return $cached = new ImageManager($driver);
            }
            if (class_exists('Intervention\\Image\\Drivers\\Gd\\Driver')) {
                $driver = new \Intervention\Image\Drivers\Gd\Driver();
                self::applyGdMemoryLimit();
                return $cached = new ImageManager($driver);
            }

            $driverString = extension_loaded('imagick') ? 'imagick' : 'gd';
            if ($driverString === 'gd') {
                self::applyGdMemoryLimit();
            }
            return $cached = new ImageManager($driverString);
        }



        throw new RuntimeException('Intervention Image is not installed.');
    }

    /**
     * Ejecuta la validación del atributo dado.
     */
    /**
     * Ejecuta la validación del atributo dado.
     *
     * Comprueba:
     *  - Que el valor sea un UploadedFile.
     *  - Firma, extensión, tamaño y decodificación con Intervention.
     *  - Dimensiones máximas.
     *  - Ausencia de patrones sospechosos en los primeros bytes.
     *
     * @param string               $attribute Nombre del atributo (e.g., "avatar").
     * @param mixed                $value     Valor a validar (debe ser UploadedFile).
     * @param \Closure(string):void $fail     Callback para señalar fallo de validación con mensaje.
     *
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->decodedImage = null;
        $this->detectedDimensions = null;

        if (! $value instanceof UploadedFile) {
            $fail(__('validation.custom.image.invalid_file'));
            return;
        }

        if (! $this->scanForSuspiciousPayload($value)) {
            $fail(__('validation.custom.image.malicious_payload'));
            return;
        }

        if (! $this->passesSignatureChecks($value)) {
            $fail(__('validation.custom.image.invalid_signature'));
            return;
        }

        if (! $this->passesDimensionChecks($value)) {
            $fail(__('validation.custom.image.invalid_dimensions'));
            return;
        }
    }

    /**
     * Verifica firma/MIME real, extensión, tamaño y decodificación con Intervention.
     *
     * @param UploadedFile $file Archivo subido.
     * @return bool True si supera todas las comprobaciones de firma.
     *
     * @throws void No lanza excepciones; registra y devuelve false ante errores.
     */
    private function passesSignatureChecks(UploadedFile $file): bool
    {
        $size = $file->getSize() ?? 0;
        if ($size <= 0 || $size > $this->maxFileSizeBytes) {
            return false;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '' || !in_array($extension, $this->constraints->allowedExtensions(), true)) {
            return false;
        }

        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        // Leer contenido una sola vez para evitar TOCTOU y mantener coherencia
        $content = file_get_contents($path);
        if ($content === false) {
            $this->logWarning('image_read_failed', $file->getClientOriginalName(), ['path' => $path]);
            return false;
        }

        // Extraer metadatos básicos desde el buffer
        $imageInfo = null;
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $imageInfo = getimagesizefromstring($content);
        } finally {
            restore_error_handler();
        }
        if (!$imageInfo) {
            return false;
        }

        $width  = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $bits   = (int) ($imageInfo['bits'] ?? 24);

        $this->detectedDimensions = [$width, $height];

        // Chequeo rápido de límites (FC)
        $minDim = $this->constraints->minDimension;
        $maxDim = $this->constraints->maxDimension;

        if (
            $width <= 0 || $height <= 0 ||
            $width > $maxDim || $height > $maxDim ||
            $width < $minDim || $height < $minDim
        ) {
            return false;
        }

        // Ratio de descompresión (image bomb)
        $uncompressed = (float) $width * (float) $height * max(1.0, (float) $bits) / 8.0;
        $ratio = $size > 0 ? $uncompressed / (float) $size : INF;
        if ($ratio > (float) $this->bombRatioThreshold) {
            return false;
        }

        // exif_imagetype (si está disponible) como verificación adicional
        if (function_exists('exif_imagetype')) {
            set_error_handler(static function (): bool {
                return true;
            });
            try {
                $exifType = exif_imagetype($path);
            } finally {
                restore_error_handler();
            }
            if ($exifType !== false) {
                $exifMime = image_type_to_mime_type((int) $exifType);
                if (!in_array($exifMime, $this->constraints->allowedMimeTypes(), true)) {
                    return false;
                }
            }
        }

        // MIME real desde buffer (fallback a path si es necesario)
        $detected = false;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_buffer($finfo, $content);
                finfo_close($finfo);
            }
        }
        if ($detected === false && function_exists('mime_content_type')) {
            // Fallback conservador sin supresión de errores
            set_error_handler(static function (): bool {
                return true;
            });
            try {
                $detected = mime_content_type($path);
            } finally {
                restore_error_handler();
            }
        }
        if ($detected === false || !in_array($detected, $this->constraints->allowedMimeTypes(), true)) {
            return false;
        }

        try {
            $this->decodedImage = $this->decodeImageFromString($content);
        } catch (Throwable $exception) {
            $this->logWarning('image_decode_failed', $file->getClientOriginalName(), $exception);
            return false;
        }

        // Normalización opcional: re-encode en memoria y re-validación heurística
        if ($this->enableNormalization) {
            try {
                $normalized = $this->reencodeToSafeBytes($this->decodedImage, is_string($detected) ? $detected : null);
            } catch (Throwable $e) {
                $this->logWarning('image_normalize_failed', $file->getClientOriginalName(), $e);
                return false;
            }

            if (!is_string($normalized) || $normalized === '') {
                return false;
            }

            // Re-scan de payloads sobre el binario normalizado
            if (!$this->scanContentForSuspiciousPayload($normalized)) {
                $this->logWarning('image_normalized_suspicious', $file->getClientOriginalName());
                return false;
            }

            // Revalidación de dimensiones y ratio bomba sobre el binario normalizado
            $info = @getimagesizefromstring($normalized);
            if (!$info) {
                return false;
            }
            $nW = (int) ($info[0] ?? 0);
            $nH = (int) ($info[1] ?? 0);
            if ($nW <= 0 || $nH <= 0 || $nW > $maxDim || $nH > $maxDim || $nW < $minDim || $nH < $minDim) {
                return false;
            }
            $nBits = (int) ($info['bits'] ?? 24);
            $nSize = strlen($normalized);
            $nUncompressed = (float) $nW * (float) $nH * max(1.0, (float) $nBits) / 8.0;
            $nRatio = $nSize > 0 ? $nUncompressed / (float) $nSize : INF;
            if ($nRatio > (float) $this->bombRatioThreshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decodifica la imagen desde contenido binario para evitar TOCTOU.
     *
     * @param string $content
     * @return object
     */
    private function decodeImageFromString(string $content): object
    {
        $manager = self::resolveImageManager();

        // Intervention v3 ->read() con buffer binario
        if (is_object($manager) && method_exists($manager, 'read')) {
            try {
                /** @var object $img */
                $img = $manager->read($content);
                return $img;
            } catch (Throwable) {
                // Fallback a archivo temporal seguro
                return $this->decodeViaTemporaryFile($content, $manager);
            }
        }

        // API alternativa: usar archivo temporal
        if ($this->decodeImage instanceof \Closure) {
            return $this->decodeViaTemporaryFile($content, $this->decodeImage);
        }
        return $this->decodeViaTemporaryFile($content, $manager);
    }

    /**
     * Fallback: escribe un archivo temporal de forma segura y decodifica desde path.
     *
     * @param string $content
     * @param object|callable $manager
     * @return object
     */
    private function decodeViaTemporaryFile(string $content, object|callable $manager): object
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'sec_img_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary file');
        }

        $cleanup = static function (string $path): void {
            if (file_exists($path) && ! unlink($path)) {
                Log::debug('secure_image_validation_temp_unlink_failed', ['path' => $path]);
            }
        };

        try {
            if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write temporary file');
            }

            if (! chmod($tempPath, 0600)) {
                Log::debug('secure_image_validation_temp_chmod_failed', ['path' => $tempPath]);
            }

            if (is_callable($manager)) {
                /** @var object $img */
                return $manager($tempPath);
            }

            if (is_object($manager) && method_exists($manager, 'read')) {
                /** @var object $img */
                return $manager->read($tempPath);
            }

            throw new RuntimeException('Unable to decode image: unsupported manager implementation.');
        } finally {
            $cleanup($tempPath);
        }
    }

    /**
     * Asegura que las dimensiones (ancho/alto) estén dentro de los límites definidos.
     *
     * @param UploadedFile $file Archivo subido.
     * @return bool True si ancho y alto están dentro de los límites configurados.
     */
    private function passesDimensionChecks(UploadedFile $file): bool
    {
        $minDim = $this->constraints->minDimension;
        $maxDim = $this->constraints->maxDimension;
        $maxMp  = $this->constraints->maxMegapixels;

        if (
            $this->decodedImage !== null
            && method_exists($this->decodedImage, 'width')
            && method_exists($this->decodedImage, 'height')
        ) {
            $width = (int) $this->decodedImage->width();
            $height = (int) $this->decodedImage->height();

            if (
                $width < $minDim || $height < $minDim ||
                $width > $maxDim || $height > $maxDim
            ) {
                return false;
            }

            $megapixels = ($width * $height) / 1_000_000;
            return $megapixels <= $maxMp;
        }

        if ($this->detectedDimensions !== null) {
            [$width, $height] = $this->detectedDimensions;

            if (
                $width < $minDim || $height < $minDim ||
                $width > $maxDim || $height > $maxDim
            ) {
                return false;
            }

            $megapixels = ($width * $height) / 1_000_000;
            if ($megapixels > $maxMp) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Escanea el segmento inicial del archivo para detectar patrones sospechosos.
     *
     * Patrones detectados (ejemplos):
     *  - Funciones peligrosas: `eval(`, `system(`, `exec(`, etc.
     *  - `base64_decode(`
     * También se reanudan las comprobaciones de etiquetas de código (`<?php`, `<?=`)
     * para impedir imágenes poliglota que intenten ejecutar PHP incrustado.
     *
     * Esta inspección es heurística y debe verse como complemento a controles
     * más profundos (p. ej., antivirus o re-encode del archivo).
     *
     * @param UploadedFile $file Archivo subido.
     * @return bool True si no se detectan cadenas sospechosas.
     */
    private function scanForSuspiciousPayload(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        try {
            $handle = new SplFileObject($path, 'rb');
            $content = $handle->fread(self::SCAN_BYTES);
        } catch (Throwable $exception) {
            $this->logWarning('image_scan_failed', $file->getClientOriginalName(), $exception);
            return false;
        }

        if ($content === false) {
            $this->logWarning('image_scan_empty', $file->getClientOriginalName());
            return false;
        }

        foreach ($this->suspiciousPayloadPatterns() as $pattern) {
            if (preg_match($pattern, $content)) {
                $this->logWarning('image_suspicious_payload', $file->getClientOriginalName(), ['pattern' => $pattern]);
                return false;
            }
        }

        return true;
    }

    /**
     * Re-encode a normalized image to safe bytes, attempting to strip metadata.
     * Tries Intervention v2 encode()/stream() and v3 toPng()/toJpeg() best-effort.
     */
    private function reencodeToSafeBytes(object $image, ?string $preferredMime = null): ?string
    {
        $format = $this->chooseSafeFormat($preferredMime);

        // Intervention v3: toPng()/toJpeg()/toWebp()...
        $method = null;
        if ($format === 'png' && method_exists($image, 'toPng')) {
            $method = 'toPng';
        } elseif (in_array($format, ['jpg', 'jpeg'], true) && method_exists($image, 'toJpeg')) {
            $method = 'toJpeg';
        } elseif ($format === 'webp' && method_exists($image, 'toWebp')) {
            $method = 'toWebp';
        }
        if ($method) {
            try {
                /** @var mixed $res */
                $res = $image->{$method}(90);
                $bytes = $this->stringifyEncoded($res);
                if (is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }
            } catch (Throwable) {
                // fallback abajo
            }
        }

        // Último recurso (v3): guardar temporalmente el objeto codificado
        if (method_exists($image, 'save')) {
            $tmp = tempnam(sys_get_temp_dir(), 'norm_img_');
            if ($tmp === false) {
                return null;
            }
            try {
                $image->save($tmp);
                $bytes = file_get_contents($tmp);
                if (! is_string($bytes) || $bytes === '') {
                    return null;
                }

                return $bytes;
            } finally {
                if (file_exists($tmp) && ! unlink($tmp)) {
                    Log::debug('secure_image_validation_temp_unlink_failed', ['path' => $tmp]);
                }
            }
        }

        return null;
    }

    private function chooseSafeFormat(?string $preferredMime): string
    {
        // Mantiene JPEG si es origen JPEG (para evitar pérdidas innecesarias), si no PNG lossless
        if (is_string($preferredMime) && str_contains($preferredMime, 'jpeg')) {
            return 'jpeg';
        }
        return 'png';
    }

    /** Intenta convertir objetos de encode/stream/encoded a string binario. */
    private function stringifyEncoded(mixed $encoded): ?string
    {
        if (is_string($encoded)) {
            return $encoded;
        }
        if (is_object($encoded)) {
            if (method_exists($encoded, 'toString')) {
                try {
                    return (string) $encoded->toString();
                } catch (Throwable) {
                }
            }
            if (method_exists($encoded, '__toString')) {
                try {
                    return (string) $encoded;
                } catch (Throwable) {
                }
            }
            if (method_exists($encoded, 'getEncoded')) {
                try {
                    return (string) $encoded->getEncoded();
                } catch (Throwable) {
                }
            }
        }
        return null;
    }

    /** Reusa los patrones de scan sobre un contenido en memoria. */
    private function scanContentForSuspiciousPayload(string $content): bool
    {
        foreach ($this->suspiciousPayloadPatterns() as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Patrón heurístico para detectar payloads sospechosos dentro de binarios de imagen.
     *
     * @return list<non-empty-string>
     */
    private function suspiciousPayloadPatterns(): array
    {
        $patterns = config('image-pipeline.suspicious_payload_patterns');

        if (!is_array($patterns) || $patterns === []) {
            return [
                '/<\?php/i',
                '/<\?=/i',
                '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s*\(/i',
                '/base64_decode\s*\(/i',
            ];
        }

        return array_values(
            array_filter(
                array_map(static function ($pattern) {
                    if (is_string($pattern) && $pattern !== '') {
                        return $pattern;
                    }

                    if (is_array($pattern) && isset($pattern['pattern']) && is_string($pattern['pattern'])) {
                        return $pattern['pattern'];
                    }

                    return null;
                }, $patterns),
                static fn ($pattern) => is_string($pattern) && $pattern !== ''
            )
        );
    }

    /**
     * Registra advertencias para auditoría y respuesta ante incidentes.
     *
     * Se añade el `user_id` cuando es posible.
     *
     * @param string                         $event    Código corto del evento (e.g., "image_decode_failed").
     * @param string                         $filename Nombre original del archivo subido.
     * @param array<string, mixed>|Throwable|null $context  Datos extra o excepción capturada.
     *
     * @return void
     */
    private function logWarning(string $event, string $filename, array|Throwable|null $context = null): void
    {
        $userId = Arr::get($this->data, 'user_id');

        if ($userId === null && function_exists('request')) {
            try {
                $user = request()->user();

                if ($user !== null) {
                    $userId = $user->getAuthIdentifier();
                }
            } catch (Throwable $ignored) {
                // Ignore; no HTTP request context available.
            }
        }

        $contextArray = [
            'event' => $event,
            'file' => $filename,
            'user_id' => $userId,
        ];

        if ($context instanceof Throwable) {
            $contextArray['exception'] = $context->getMessage();
        } elseif (is_array($context)) {
            $contextArray = array_merge($contextArray, $context);
        }

        Log::warning('secure_image_validation', $contextArray);
    }
}
