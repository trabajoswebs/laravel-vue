<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;
use App\Application\Media\Contracts\FileConstraints;
use App\Infrastructure\Media\Security\ImageMetadataReader;
use App\Infrastructure\Media\Security\ImageNormalizer;
use App\Infrastructure\Media\Security\MimeNormalizer;
use App\Infrastructure\Media\Security\PayloadScanner;
use App\Infrastructure\Media\Security\Upload\UploadValidationLogger;

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
 *  use App\Infrastructure\Http\Rules\SecureImageValidation;
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
 * @see https://www.php.net/manual/en/function.finfo-file.php       Detección MIME
 * @see https://image.intervention.io/       Uso de Intervention Image
 */
class SecureImageValidation implements ValidationRule, DataAwareRule
{
    /**
     * Umbral de ratio de descompresión para detectar "image bombs".
     * Si (ancho*alto*bits/8) / size_bytes > RATIO → sospechoso.
     */
    private const BOMB_RATIO_THRESHOLD = 20;

    /** Máximo absoluto de píxeles permitidos (protege contra imágenes gigantes). */
    private const MAX_PIXEL_COUNT = 50_000_000; // ~8K x 8K

    /** Fracción del memory_limit que se permite consumir para decodificación. */
    private const MEMORY_SAFETY_RATIO = 0.3;

    /** Directorio temporal privado para aislar archivos antes de analizarlos. */
    private const SECURE_TEMP_SUBDIR = 'uploads/tmp-secure';

    /** Extensiones peligrosas que no deben aparecer antes de la extensión real. */
    private const FORBIDDEN_SECONDARY_EXTENSIONS = [
        'php', 'phtml', 'pht', 'php3', 'php4', 'php5', 'phps', 'phar',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'exe', 'sh', 'bash', 'bin',
    ];

    /**
     * Lista blanca estricta de MIME types permitidos.
     *
     * @var array<int,string>
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    /**
     * Mapeo de extensiones permitidas a sus MIME válidos.
     *
     * @var array<string, array<int,string>>
     */
    private const EXTENSION_MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
    ];

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
     * Lista de MIME permitidos derivados de FileConstraints (normalizados).
     *
     * @var array<int,string>
     */
    private array $constraintsAllowedMimes = [];

    private PayloadScanner $payloadScanner;

    private ImageMetadataReader $metadataReader;

    private ImageNormalizer $imageNormalizer;

    private UploadValidationLogger $validationLogger;

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

    /** Tiempo máximo permitido para decodificar la imagen (segundos). */
    private float $decodeTimeoutSeconds;

    /** Permite aceptar MIMEs válidos aunque la extensión no coincida (debe habilitarse explícitamente). */
    private bool $allowMimeExtensionMismatch = false;

    /** Permite caer a la lista global de MIMEs permitidos cuando FileConstraints no lo incluye. */
    private bool $allowGlobalMimeFallback = false;

    private bool $userContextResolved = false;

    private string|int|null $userIdFromContext = null;

    /**
     * Constructor de la regla de validación.
     *
     * Inicializa la regla y permite la inyección de dependencias para el decodificador,
     * el límite de tamaño de archivo y otras opciones de seguridad.
     *
     * @param callable|null        $decodeImage        Callable opcional para decodificar el archivo de imagen. Si es null, usa el decodificador predeterminado de Intervention.
     * @param int|null             $maxFileSizeBytes   Límite máximo de tamaño de archivo en bytes. Si es null, se usa el valor configurado en FileConstraints.
     * @param bool|null            $normalize          Habilita o deshabilita la normalización adicional (re-encode).
     * @param int|null             $bombRatioThreshold Umbral personalizado para detectar image bombs. Si es null, se usa el valor de configuración.
     * @param FileConstraints|null $constraints        Instancia de FileConstraints que define los límites comunes. Si es null, se obtiene desde el contenedor de servicios.
     * @param PayloadScanner|null  $payloadScanner     Servicio que detecta payloads sospechosos.
     * @param ImageMetadataReader|null $metadataReader Servicio que obtiene metadatos confiables.
     * @param ImageNormalizer|null $imageNormalizer    Servicio que re-encodea la imagen.
     * @param UploadValidationLogger|null $validationLogger Logger especializado.
     */
    public function __construct(
        ?callable $decodeImage = null,
        ?int $maxFileSizeBytes = null,
        ?bool $normalize = null,
        ?int $bombRatioThreshold = null,
        ?FileConstraints $constraints = null,
        ?PayloadScanner $payloadScanner = null,
        ?ImageMetadataReader $metadataReader = null,
        ?ImageNormalizer $imageNormalizer = null,
        ?UploadValidationLogger $validationLogger = null,
    ) {
        // Inicializa las restricciones y convierte los MIME permitidos a minúsculas para comparaciones consistentes
        $this->constraints = $constraints ?? app(FileConstraints::class);
        $allowedMimes = [];
        foreach ($this->constraints->allowedMimeTypes() as $mime) {
            $normalized = MimeNormalizer::normalize($mime);
            if ($normalized !== null) {
                $allowedMimes[] = $normalized;
            }
        }
        $this->constraintsAllowedMimes = array_values(array_unique($allowedMimes));

        // Configura el callable para decodificar la imagen, usando uno predeterminado si no se proporciona
        $this->decodeImage = $decodeImage instanceof \Closure
            ? $decodeImage
            : $this->makeDefaultDecoder($decodeImage);

        // Establece el tamaño máximo de archivo, priorizando el valor pasado explícitamente
        $this->maxFileSizeBytes = $maxFileSizeBytes
            ?? $this->constraints->maxBytes;

        $extensions = $this->constraints->allowedExtensions();
        $mimeTypes = $this->constraintsAllowedMimes;
        if ($this->maxFileSizeBytes <= 0) {
            throw new RuntimeException('SecureImageValidation requires a positive max file size.');
        }
        if ($extensions === []) {
            throw new RuntimeException('SecureImageValidation requires at least one allowed extension. Check FileConstraints.');
        }
        if ($mimeTypes === []) {
            throw new RuntimeException('SecureImageValidation requires at least one allowed mime type. Check FileConstraints.');
        }
        if ($this->constraints->minDimension <= 0 || $this->constraints->maxDimension <= 0) {
            throw new RuntimeException('SecureImageValidation requires positive dimension bounds.');
        }
        if ($this->constraints->minDimension > $this->constraints->maxDimension) {
            throw new RuntimeException('SecureImageValidation requires minDimension <= maxDimension.');
        }

        $this->payloadScanner = $payloadScanner ?? app(PayloadScanner::class);
        $this->metadataReader = $metadataReader ?? app(ImageMetadataReader::class);
        $this->imageNormalizer = $imageNormalizer
            ?? app(ImageNormalizer::class, ['constraints' => $this->constraints]);
        $this->validationLogger = $validationLogger ?? app(UploadValidationLogger::class);

        // Opciones de endurecimiento de seguridad
        $normalizationEnabled = config('image-pipeline.normalization.enabled', false);
        $this->enableNormalization = (bool) ($normalize ?? $normalizationEnabled);
        $this->allowMimeExtensionMismatch = (bool) config('image-pipeline.allow_mime_extension_mismatch', false);
        $this->allowGlobalMimeFallback = (bool) config('image-pipeline.allow_global_mime_fallback', false);

        // Configura el umbral para detectar image bombs, con un valor predeterminado y verificación de validez
        $configRatio = (int) config('image-pipeline.bomb_ratio_threshold', self::BOMB_RATIO_THRESHOLD);
        if ($configRatio <= 0) {
            $configRatio = self::BOMB_RATIO_THRESHOLD;
        }
        if ($bombRatioThreshold !== null && $bombRatioThreshold > 0) {
            $this->bombRatioThreshold = $bombRatioThreshold;
        } else {
            $this->bombRatioThreshold = $configRatio;
        }

        // Establece el tiempo máximo permitido para decodificar la imagen
        $this->decodeTimeoutSeconds = max(0.1, (float) config('image-pipeline.decode_timeout_seconds', 5));
    }

    /**
     * Copia el archivo a un directorio privado con nombre impredecible.
     *
     * Aislar el archivo mitiga ataques TOCTOU porque todas las operaciones
     * posteriores se realizan sobre una copia controlada cuya ruta y permisos
     * están bajo nuestro control dentro de storage/.
     */
    private function isolateUploadedFile(UploadedFile $file): string
    {
        $sourcePath = $file->getRealPath();
        if (!is_string($sourcePath) || $sourcePath === '') {
            throw new RuntimeException('Uploaded file missing path.');
        }

        $secureDir = storage_path('app/' . self::SECURE_TEMP_SUBDIR);
        if (!is_dir($secureDir) && !@mkdir($secureDir, 0700, true) && !is_dir($secureDir)) {
            throw new RuntimeException('Unable to prepare secure upload directory.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = 'bin';
        }

        $tempName = Str::uuid()->toString() . '.' . $extension;
        $targetPath = $secureDir . DIRECTORY_SEPARATOR . $tempName;

        if (!@copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Failed to isolate uploaded file.');
        }

        @chmod($targetPath, 0600);
        clearstatcache(true, $sourcePath);
        clearstatcache(true, $targetPath);

        $originalSize = @filesize($sourcePath);
        $copiedSize = @filesize($targetPath);
        if ($originalSize !== false && $copiedSize !== false && $originalSize !== $copiedSize) {
            @unlink($targetPath);
            throw new RuntimeException('Isolated file size mismatch detected.');
        }

        return $targetPath;
    }

    /**
     * Elimina la copia aislada una vez terminada la validación.
     */
    private function cleanupIsolatedFile(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Inyecta el contexto de datos del validador (p. ej., user_id, otros campos del request).
     *
     * @param array<string, mixed> $data El array de datos del contexto de validación.
     * @return static Devuelve la instancia actual para permitir encadenamiento.
     *
     * @inheritDoc
     */
    public function setData(array $data): static
    {
        $this->data = $data;
        $this->userContextResolved = false;
        $this->userIdFromContext = null;
        return $this;
    }

    /**
     * Crea un decodificador predeterminado que utiliza Intervention Image v3.
     *
     * Este método maneja la lógica de inicializar el ImageManager de Intervention
     * y leer la imagen desde la ruta del archivo.
     *
     * @param callable|null $decoder Decodificador personalizado opcional.
     * @return \Closure(string):object Un closure que toma una ruta de archivo y devuelve un objeto de imagen.
     */
    private function makeDefaultDecoder(?callable $decoder = null): \Closure
    {
        if ($decoder !== null) {
            // Si se proporciona un decodificador personalizado, envuélvelo en un closure
            return static fn(string $path): object => $decoder($path);
        }

        // Devuelve un closure que encapsula la lógica de creación y uso del ImageManager
        return static function (string $path): object {
            static $manager;

            // Inicializa el ImageManager una sola vez (patrón singleton estático)
            if ($manager === null) {
                $manager = self::resolveImageManager();
            }

            // Intenta leer la imagen usando el manager (objeto o callable)
            if (is_callable($manager)) {
                $image = $manager($path);
            } elseif (is_object($manager) && method_exists($manager, 'read')) {
                $image = $manager->read($path);
            } else {
                throw new RuntimeException('Unable to decode image: unsupported manager implementation.');
            }

            // Valida que el resultado sea un objeto
            if (! is_object($image)) {
                throw new RuntimeException('Unable to decode image: decoder did not return an object.');
            }

            return $image;
        };
    }

    /**
     * Aplica un límite de memoria específico para operaciones de GD si está configurado.
     *
     * Esta función ajusta el límite de memoria de PHP para ayudar a prevenir errores
     * de memoria durante el procesamiento intensivo de imágenes con GD.
     *
     * @return void
     */
    private static function applyGdMemoryLimit(): void
    {
        // Obtiene el límite de memoria configurado para GD en MB
        $gdMb = (int) config('image-pipeline.resource_limits.gd_memory_mb', 0);

        if ($gdMb > 0) {
            // Establece el límite de memoria, asegurando un mínimo de 64M
            @ini_set('memory_limit', max(64, $gdMb) . 'M');
        }
    }

    private static function canUseImagickDriver(): bool
    {
        return class_exists('Intervention\\Image\\Drivers\\Imagick\\Driver')
            && class_exists(\Imagick::class)
            && extension_loaded('imagick');
    }

    /**
     * Aplica límites defensivos cuando se permite Imagick.
     *
     * Estos límites no sustituyen la obligación de contar con una policy.xml
     * endurecida a nivel de sistema, pero reducen el impacto de cargas maliciosas.
     */
    private static function enforceImagickResourceGuards(): void
    {
        if (!class_exists(\Imagick::class)) {
            throw new RuntimeException('Imagick extension not available.');
        }

        $limits = (array) config('image-pipeline.imagick.resource_limits', []);
        $memory = (int) ($limits['memory'] ?? (256 * 1024 * 1024));
        $map = (int) ($limits['map'] ?? (512 * 1024 * 1024));
        $area = (int) ($limits['area'] ?? (128 * 1024 * 1024));
        $fileHandles = (int) ($limits['file'] ?? 32);
        $time = (int) ($limits['time'] ?? 60);

        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, $memory);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, $map);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, $area);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_FILE, $fileHandles);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_TIME, $time);

        if (config('image-pipeline.imagick.require_policy_confirmation', true)) {
            $policyPath = config('image-pipeline.imagick.policy_path');
            if ($policyPath !== null && !is_file($policyPath)) {
                throw new RuntimeException('Imagick policy file not found or unreadable: ' . $policyPath);
            }
        }
    }

    /**
     * Resuelve e instancia un gestor de imágenes de Intervention Image adecuado.
     *
     * Detecta la versión instalada de Intervention Image (v3) y crea una instancia
     * del ImageManager con el driver más apropiado disponible (Imagick, GD).
     *
     * @return object|callable Devuelve una instancia del ImageManager o un callable.
     */
    private static function resolveImageManager(): object|callable
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }

        $preferredDriver = strtolower((string) config('image-pipeline.driver', 'gd'));

        if ($preferredDriver === 'imagick') {
            if (!self::canUseImagickDriver()) {
                throw new RuntimeException('Imagick driver requested but not available.');
            }

            self::enforceImagickResourceGuards();
            $driver = new \Intervention\Image\Drivers\Imagick\Driver();
            return $cached = new ImageManager($driver);
        }

        if (class_exists('Intervention\\Image\\Drivers\\Gd\\Driver')) {
            self::applyGdMemoryLimit();
            $driver = new \Intervention\Image\Drivers\Gd\Driver();
            return $cached = new ImageManager($driver);
        }

        if (self::canUseImagickDriver()) {
            self::enforceImagickResourceGuards();
            $driver = new \Intervention\Image\Drivers\Imagick\Driver();
            return $cached = new ImageManager($driver);
        }

        if (interface_exists('Intervention\\Image\\Interfaces\\ImageManagerInterface') && function_exists('app')) {
            try {
                return $cached = app('Intervention\\Image\\Interfaces\\ImageManagerInterface');
            } catch (Throwable $e) {
                throw new RuntimeException('Unable to resolve Intervention Image manager', 0, $e);
            }
        }

        throw new RuntimeException('Intervention Image is not installed.');
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
        // Resetea estados internos de la instancia para esta validación
        $this->decodedImage = null;
        $this->detectedDimensions = null;

        // Valida que el valor sea un archivo subido válido
        if (! $value instanceof UploadedFile) {
            $fail(__('validation.custom.image.invalid_file'));
            return;
        }

        if (! $value->isValid()) {
            $fail(__('validation.custom.image.invalid_file'));
            return;
        }

        try {
            $path = $this->isolateUploadedFile($value);
        } catch (Throwable $e) {
            $this->logWarning('image_isolation_failed', $value->getClientOriginalName() ?? 'unknown', $e);
            $fail(__('validation.custom.image.invalid_file'));
            return;
        }

        try {
            // Escanea únicamente los primeros bytes desde disco para minimizar uso de memoria
            if (! $this->payloadScanner->fileIsClean($path, $value->getClientOriginalName(), $this->resolveUserId())) {
                $fail(__('validation.custom.image.malicious_payload'));
                return;
            }

            // Realiza comprobaciones de firma y metadatos
            if (! $this->passesSignatureChecks($value, $path)) {
                $fail(__('validation.custom.image.invalid_signature'));
                return;
            }

            // Realiza comprobaciones de dimensiones
            if (! $this->passesDimensionChecks($value)) {
                $fail(__('validation.custom.image.invalid_dimensions'));
                return;
            }
        } finally {
            $this->cleanupIsolatedFile($path ?? null);
        }
    }

    /**
     * Verifica firma/MIME real, extensión, tamaño y decodificación con Intervention.
     *
     * Esta función es el núcleo de la validación de firma. Recorre la ruta del archivo
     * en disco para leer metadatos, MIME y detectar payloads antes de decodificarlo.
     *
     * @param UploadedFile $file Archivo subido.
     * @param string $path Ruta del archivo en disco.
     * @return bool True si supera todas las comprobaciones de firma.
     *
     * @throws void No lanza excepciones; registra y devuelve false ante errores.
     */
    private function passesSignatureChecks(UploadedFile $file, string $path): bool
    {
        // Comprueba el tamaño del archivo sin cargarlo íntegramente
        $size = (int) ($file->getSize() ?? @filesize($path) ?? 0);
        if ($size <= 0 || $size > $this->maxFileSizeBytes) {
            return false;
        }

        $originalFilename = $file->getClientOriginalName() ?? 'upload';
        if ($this->hasDangerousSecondaryExtension($originalFilename)) {
            $this->logWarning('image_dangerous_extension_layer', $originalFilename);
            return false;
        }

        // Comprueba la extensión del archivo
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '' || !in_array($extension, $this->constraints->allowedExtensions(), true)) {
            return false;
        }

        $clientMime = strtolower((string) $file->getMimeType());
        if ($clientMime !== '' && !str_starts_with($clientMime, 'image/')) {
            return false;
        }

        // Extrae metadatos básicos de la imagen directamente desde el archivo en disco
        $imageInfo = $this->metadataReader->readImageInfo($path);
        if (!is_array($imageInfo)) {
            return false;
        }

        // Almacena las dimensiones detectadas para uso posterior
        $width  = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $this->detectedDimensions = [$width, $height];

        $maxMegapixels = max(0.0, (float) $this->constraints->maxMegapixels);
        if (!$this->validateDimensionsAndBombs($width, $height, $size, $maxMegapixels, $originalFilename)) {
            return false;
        }

        // Determina el MIME confiable del archivo
        $detectedMime = $this->metadataReader->resolveTrustedMime(
            $path,
            fn(string $mime): bool => $this->isMimeAllowed($mime),
            $imageInfo,
            $originalFilename,
            $this->resolveUserId(),
        );
        if ($detectedMime === null) {
            return false;
        }

        // Comprueba que la extensión coincida con el MIME detectado
        if (!$this->mimeMatchesExtension($extension, $detectedMime, $originalFilename)) {
            return false;
        }

        // Comprueba si la imagen es animada (si está prohibido)
        if ($this->metadataReader->isAnimated($detectedMime, $path)) {
            return false;
        }

        // Decodifica la imagen usando Intervention para verificar su integridad
        $startDecode = microtime(true);
        try {
            $this->decodedImage = $this->decodeImageFromPath($path);
        } catch (Throwable $exception) {
            // Registra el fallo y devuelve false
            $this->logWarning('image_decode_failed', $file->getClientOriginalName(), $exception);
            return false;
        }
        // Comprueba si la decodificación tardó más de lo esperado (solo métrica, no aborta el driver).
        if ((microtime(true) - $startDecode) > $this->decodeTimeoutSeconds) {
            $this->logWarning('image_decode_timeout', $file->getClientOriginalName(), [
                'timeout_seconds' => $this->decodeTimeoutSeconds,
            ]);
            return false;
        }

        // Aplica normalización opcional si está habilitada
        if ($this->enableNormalization) {
            try {
                $normalized = $this->imageNormalizer->reencode($this->decodedImage, $detectedMime);
            } catch (Throwable $e) {
                $this->logWarning('image_normalize_failed', $file->getClientOriginalName(), $e);
                return false;
            }

            if (!is_string($normalized) || $normalized === '') {
                return false;
            }

            try {
                $contentClean = $this->payloadScanner->contentIsClean($normalized, $file->getClientOriginalName(), $this->resolveUserId());
            } catch (RuntimeException $scannerException) {
                $this->logWarning('image_normalized_pattern_invalid', $file->getClientOriginalName(), $scannerException);
                return false;
            }

            if (! $contentClean) {
                $this->logWarning('image_normalized_suspicious', $file->getClientOriginalName());
                return false;
            }

            // Vuelve a validar dimensiones y ratio de bomba en el binario normalizado
            $info = @getimagesizefromstring($normalized);
            if (!$info) {
                return false;
            }
            $nW = (int) ($info[0] ?? 0);
            $nH = (int) ($info[1] ?? 0);
            $nSize = strlen($normalized);
            if (!$this->validateDimensionsAndBombs($nW, $nH, $nSize, $maxMegapixels, $originalFilename)) {
                return false;
            }

            if (! $this->imageNormalizer->overwrite($path, $normalized)) {
                $this->logWarning('image_normalize_write_failed', $file->getClientOriginalName());
                return false;
            }

            // Actualiza el estado interno para reflejar el binario normalizado
            $this->detectedDimensions = [$nW, $nH];
            $size = $nSize;
        }

        return true;
    }

    private function getProcessMemoryLimit(): ?int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return null; // Sin límite definido
        }

        return $this->convertToBytes($limit);
    }

    private function convertToBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 0;
        }

        $unit = strtolower(substr($trimmed, -1));
        $number = (float) $trimmed;

        switch ($unit) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
                break;
            default:
                $number = (float) $trimmed;
        }

        return (int) max(0, $number);
    }

    /**
     * Decodifica la imagen directamente desde la ruta proporcionada.
     *
     * Mantiene un único punto de entrada para la estrategia de decodificación,
     * ya sea inyectada o la predeterminada basada en Intervention Image.
     */
    private function decodeImageFromPath(string $path): object
    {
        $decoder = $this->decodeImage;

        return $decoder($path);
    }

    /**
     * Verifica que el MIME detectado esté autorizado para la extensión declarada.
     *
     * - Si la extensión tiene su propia whitelist (ej. «jpg» → ['image/jpeg'])
     *   se exige que el MIME aparezca en esa lista.
     * - Si la extensión no tiene mapeo propio, se acepta cualquier MIME presente
     *   en la whitelist derivada de FileConstraints.
     * - Cuando `allow_mime_extension_mismatch` está activo y el MIME es válido, se
     *   permite continuar aunque no coincidan, dejando registro del evento.
     *
     * Utiliza la normalización interna para tratar casos como:
     * `image/pjpeg` → `image/jpeg` o `image/x-png` → `image/png`.
     *
     * @param string      $extension        Extensión del archivo SIN punto, en cualquier casing.
     * @param string      $mime             MIME devuelto por el detector (puede contener charset).
     * @param string|null $originalFilename Nombre original para logging de discrepancias.
     *
     * @return bool true cuando el par extensión/MIME es coherente y permitido.
     */
    private function mimeMatchesExtension(string $extension, string $mime, ?string $originalFilename = null): bool
    {
        $normalizedMime = MimeNormalizer::normalize($mime);
        if ($normalizedMime === null) {
            return false;
        }

        $normalizedExtension = strtolower($extension);
        $extensionMimes = self::EXTENSION_MIME_MAP[$normalizedExtension] ?? [];

        // Extensión sin mapeo específico: se valora contra la lista global
        $strictMatch = $extensionMimes === []
            ? in_array($normalizedMime, $this->constraintsAllowedMimes, true)
            : in_array($normalizedMime, $extensionMimes, true);

        if ($strictMatch) {
            return true;
        }

        $this->logWarning('image_extension_mime_mismatch', $originalFilename ?? 'unknown', [
            'extension' => $normalizedExtension,
            'mime' => $normalizedMime,
            'allow_mismatch' => $this->allowMimeExtensionMismatch,
        ]);

        if ($this->allowMimeExtensionMismatch) {
            // Acepta solo si el MIME sigue pasando las listas blancas.
            return $this->isMimeAllowed($normalizedMime);
        }

        return false;
    }

    /**
     * Detecta extensiones dobles o múltiples con segmentos peligrosos.
     *
     * Ejemplos:
     *  - "avatar.php.jpg" → rechazado.
     *  - "foto.profile.png" → permitido.
     */
    private function hasDangerousSecondaryExtension(string $filename): bool
    {
        $trimmed = trim($filename);
        if ($trimmed === '') {
            return true;
        }

        $parts = array_values(array_filter(explode('.', $trimmed)));
        if (count($parts) <= 1) {
            return false; // No hay sub-extensiones
        }

        $segments = array_map(static fn(string $part): string => strtolower($part), $parts);
        foreach (array_slice($segments, 0, -1) as $segment) {
            if (in_array($segment, self::FORBIDDEN_SECONDARY_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si un MIME está permitido según el perfil activo.
     *
     * Prioriza los MIME provenientes de FileConstraints y puede, opcionalmente,
     * caer a la lista global embebida para reducir falsos positivos. Este orden
     * evita que la lista global sobrepase configuraciones más estrictas.
     *
     * @param string $mime El MIME a verificar.
     * @return bool True si el MIME es permitido.
     */
    private function isMimeAllowed(string $mime): bool
    {
        $normalized = MimeNormalizer::normalize($mime);
        if ($normalized === null) {
            return false;
        }

        // Prioridad 1: respeta las restricciones específicas del perfil.
        if (in_array($normalized, $this->constraintsAllowedMimes, true)) {
            return true;
        }

        // Prioridad 2: permite la lista global solo cuando la configuración lo autoriza.
        if (
            $this->allowGlobalMimeFallback
            && in_array($normalized, self::ALLOWED_MIME_TYPES, true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Asegura que las dimensiones (ancho/alto) estén dentro de los límites definidos.
     *
     * Esta función comprueba las dimensiones reales de la imagen decodificada
     * contra los límites configurados en FileConstraints.
     *
     * @param UploadedFile $file Archivo subido.
     * @return bool True si ancho y alto están dentro de los límites configurados.
     */
    private function passesDimensionChecks(UploadedFile $file): bool
    {
        $minDim = $this->constraints->minDimension;
        $maxDim = $this->constraints->maxDimension;
        $maxMp  = $this->constraints->maxMegapixels;

        // Si ya se decodificó la imagen, usa sus dimensiones
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

        // Si solo se detectaron dimensiones previamente, úsalas
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
     * Valida dimensiones, ratio de bomb y megapíxeles con la configuración actual.
     *
     * Comprueba que:
     *  - anchura y altura estén dentro del rango permitido (minDimension…maxDimension)
     *  - la imagen no supere el límite de megapíxeles configurado
     *  - el ratio “bomba” (tamaño descomprimido / tamaño real) no exceda el umbral
     *    establecido ($this->bombRatioThreshold)
     *
     * El tamaño descomprimido se calcula como `width * height * 4` (32 bpp RGBA).
     * Si $size es 0 o negativo el ratio se considera infinito (INF) y la imagen
     * rechazada cuando el umbral es finito.
     *
     * @param int   $width         Anchura en píxeles.
     * @param int   $height        Altura en píxeles.
     * @param int   $size          Tamaño real del fichero en bytes.
     * @param float $maxMegapixels Máximo de megapíxeles permitido (0 = sin límite).
     *
     * @return bool true si todas las comprobaciones pasan, false en caso contrario.
     */
    private function validateDimensionsAndBombs(
        int $width,
        int $height,
        int $size,
        float $maxMegapixels,
        ?string $filename = null
    ): bool
    {
        $minDim = $this->constraints->minDimension;
        $maxDim = $this->constraints->maxDimension;

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        if ($width < $minDim || $width > $maxDim || $height < $minDim || $height > $maxDim) {
            return false;
        }

        $totalPixels = (int) $width * (int) $height;
        if ($totalPixels > self::MAX_PIXEL_COUNT) {
            $this->logWarning('image_pixels_limit_exceeded', $filename ?? 'unknown', [
                'pixels' => $totalPixels,
                'limit' => self::MAX_PIXEL_COUNT,
            ]);
            return false;
        }

        $estimatedBytes = (float) $totalPixels * 4.0; // Aproximación RGBA
        $ratio = $size > 0 ? $estimatedBytes / (float) $size : INF;
        if ($ratio > (float) $this->bombRatioThreshold) {
            $this->logWarning('image_bomb_ratio', $filename ?? 'unknown', [
                'estimated_bytes' => (int) $estimatedBytes,
                'file_bytes' => $size,
                'ratio' => $ratio,
                'threshold' => $this->bombRatioThreshold,
            ]);
            return false;
        }

        $memoryLimit = $this->getProcessMemoryLimit();
        if ($memoryLimit !== null) {
            $used = memory_get_usage(true);
            $budget = max(0, (int) ($memoryLimit * self::MEMORY_SAFETY_RATIO));
            $available = max(0, $memoryLimit - $used);
            $allowed = min($budget, $available);
            if ($allowed > 0 && $estimatedBytes > $allowed) {
                $this->logWarning('image_memory_budget_exceeded', $filename ?? 'unknown', [
                    'estimated_bytes' => (int) $estimatedBytes,
                    'allowed_bytes' => $allowed,
                    'memory_limit' => $memoryLimit,
                ]);
                return false;
            }
        }

        if ($maxMegapixels > 0.0) {
            $megapixels = (($width * $height) > 0) ? (($width * $height) / 1_000_000) : 0.0;
            if ($megapixels > $maxMegapixels) {
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
        $this->validationLogger->warning($event, $filename, $context, $this->resolveUserId());
    }

    private function resolveUserId(): string|int|null
    {
        if ($this->userContextResolved) {
            return $this->userIdFromContext;
        }

        $this->userIdFromContext = Arr::get($this->data, 'user_id');

        if ($this->userIdFromContext === null && function_exists('request')) {
            try {
                $user = request()->user();
                if ($user !== null) {
                    $this->userIdFromContext = $user->getAuthIdentifier();
                }
            } catch (Throwable) {
                // Contexto HTTP no disponible: continuar sin user_id.
            }
        }

        $this->userContextResolved = true;

        return $this->userIdFromContext;
    }
}
