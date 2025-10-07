<?php

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
 * @see https://www.php.net/manual/en/function.finfo-file.php Detección MIME
 * @see https://image.intervention.io/ Uso de Intervention Image
 */
class SecureImageValidation implements ValidationRule, DataAwareRule
{
    /**
     * Lista blanca estricta de tipos MIME permitidos.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /**
     * Extensiones válidas asociadas a los MIME permitidos.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'avif',
    ];

    /**
     * Dimensión máxima (ancho y alto) permitida para la imagen, en píxeles.
     *
     * @var int
     */
    private const MAX_DIMENSION = 8000;

    /**
     * Tamaño máximo del archivo en bytes por defecto (5 MB).
     */
    private const DEFAULT_MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * Número de bytes iniciales a escanear para detectar payloads sospechosos.
     *
     * @var int
     */
    private const SCAN_BYTES = 10 * 1024;

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
     * Callable responsible for decoding the image path.
     *
     * @var \Closure(string):object
     */
    private \Closure $decodeImage;

    /**
     * Tamaño máximo en bytes permitido para este contexto.
     */
    private int $maxFileSizeBytes;

    /**
     * Initialise the rule and allow dependency injection of the decoder and size limit.
     */
    public function __construct(?callable $decodeImage = null, ?int $maxFileSizeBytes = null)
    {
        $this->decodeImage = $decodeImage instanceof \Closure
            ? $decodeImage
            : $this->makeDefaultDecoder($decodeImage);

        $this->maxFileSizeBytes = $maxFileSizeBytes ?? self::DEFAULT_MAX_FILE_SIZE_BYTES;
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
     * Create a default decoder that supports Intervention Image v2 and v3.
     */
    private function makeDefaultDecoder(?callable $decoder = null): \Closure
    {
        if ($decoder !== null) {
            return static fn (string $path): object => $decoder($path);
        }

        return static function (string $path): object {
            static $manager;

            if ($manager === null) {
                $manager = self::resolveImageManager();
            }

            if (is_callable($manager)) {
                $image = $manager($path);
            } elseif (method_exists($manager, 'read')) {
                $image = $manager->read($path);
            } elseif (method_exists($manager, 'make')) {
                $image = $manager->make($path);
            } elseif (method_exists($manager, 'decode')) {
                $image = $manager->decode($path);
            } else {
                throw new RuntimeException('Unable to decode image: unsupported manager implementation.');
            }

            if (! is_object($image)) {
                throw new RuntimeException('Unable to decode image: decoder did not return an object.');
            }

            return $image;
        };
    }

    /**
     * Resolve a suitable Intervention Image manager instance or callable.
     *
     * @return object|callable
     */
    private static function resolveImageManager(): object|callable
    {
        if (class_exists('Intervention\\Image\\Interfaces\\ImageManagerInterface')) {
            if (function_exists('app')) {
                return app('Intervention\\Image\\Interfaces\\ImageManagerInterface');
            }

            throw new RuntimeException('Intervention Image v3 interface detected but Laravel container unavailable.');
        }

        if (class_exists('Intervention\\Image\\ImageManager')) {
            return self::resolveFromContainer('Intervention\\Image\\ImageManager');
        }

        if (class_exists('Intervention\\Image\\ImageManagerStatic')) {
            return static fn (string $path): object => \Intervention\Image\ImageManagerStatic::make($path);
        }

        throw new RuntimeException('Intervention Image is not installed.');
    }

    /**
     * Attempt to resolve a class via the Laravel container, falling back to direct instantiation.
     */
    private static function resolveFromContainer(string $class): object
    {
        if (function_exists('app')) {
            return app($class);
        }

        if ($class === ImageManager::class) {
            $driver = extension_loaded('imagick') ? 'imagick' : 'gd';

            return new ImageManager(['driver' => $driver]);
        }

        return new $class();
    }

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

        if (! $this->passesSignatureChecks($value)) {
            $fail(__('validation.custom.image.invalid_signature'));
            return;
        }

        if (! $this->passesDimensionChecks($value)) {
            $fail(__('validation.custom.image.invalid_dimensions'));
            return;
        }

        if (! $this->scanForSuspiciousPayload($value)) {
            $fail(__('validation.custom.image.malicious_payload'));
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
        if ($file->getSize() > $this->maxFileSizeBytes) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return false;
        }

        $path = $file->getRealPath();
        if ($path === false) {
            return false;
        }

        $imageInfo = @getimagesize($path);

        if (! $imageInfo) {
            return false;
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $this->detectedDimensions = [$width, $height];

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $path) : false;

        if ($finfo) {
            finfo_close($finfo);
        }

        if ($detected === false || ! in_array($detected, self::ALLOWED_MIME_TYPES, true)) {
            return false;
        }

        try {
            // Decodifica para verificar que es una imagen válida.
            $decoder = $this->decodeImage;
            $this->decodedImage = $decoder($path);
        } catch (Throwable $exception) {
            $this->logWarning('image_decode_failed', $file->getClientOriginalName(), $exception);
            return false;
        }

        return true;
    }

    /**
     * Asegura que las dimensiones (ancho/alto) estén dentro de los límites definidos.
     *
     * @param UploadedFile $file Archivo subido.
     * @return bool True si ancho y alto son <= MAX_DIMENSION.
     */
    private function passesDimensionChecks(UploadedFile $file): bool
    {
        if ($this->detectedDimensions !== null) {
            [$width, $height] = $this->detectedDimensions;

            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                return false;
            }

            return true;
        }

        if ($this->decodedImage !== null && method_exists($this->decodedImage, 'width') && method_exists($this->decodedImage, 'height')) {
            return $this->decodedImage->width() <= self::MAX_DIMENSION
                && $this->decodedImage->height() <= self::MAX_DIMENSION;
        }

        return false;
    }

    /**
     * Escanea el segmento inicial del archivo para detectar patrones sospechosos.
     *
     * Patrones detectados (ejemplos):
     *  - `<?php`
     *  - Funciones peligrosas: `eval(`, `system(`, `exec(`, etc.
     *  - `base64_decode(`
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

        /** @var list<non-empty-string> $patterns */
        $patterns = [
            '/<\?php/i',
            '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s*\(/i',
            '/base64_decode\s*\(/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $this->logWarning('image_suspicious_payload', $file->getClientOriginalName(), ['pattern' => $pattern]);
                return false;
            }
        }

        return true;
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
