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
 * @see https://www.php.net/manual/en/function.finfo-file.php       Detección MIME
 * @see https://image.intervention.io/       Uso de Intervention Image
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
     * Alias canónicos para determinados MIME reconocidos como equivalentes.
     *
     * @var array<string,string>
     */
    private const MIME_ALIAS_MAP = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/x-png' => 'image/png',
        'image/x-webp' => 'image/webp',
        'image/x-avif' => 'image/avif',
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
     */
    public function __construct(
        ?callable $decodeImage = null,
        ?int $maxFileSizeBytes = null,
        ?bool $normalize = null,
        ?int $bombRatioThreshold = null,
        ?FileConstraints $constraints = null,
    ) {
        // Inicializa las restricciones y convierte los MIME permitidos a minúsculas para comparaciones consistentes
        $this->constraints = $constraints ?? app(FileConstraints::class);
        $allowedMimes = [];
        foreach ($this->constraints->allowedMimeTypes() as $mime) {
            $normalized = self::normalizeMime($mime);
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
        // Variable estática para cachear la instancia y evitar reinicializaciones
        static $cached;
        if ($cached !== null) {
            return $cached;
        }

        // Verifica si la interfaz de Intervention Image v3 existe (indicativo de la versión v3)
        if (interface_exists('Intervention\\Image\\Interfaces\\ImageManagerInterface')) {
            // Intenta obtener el ImageManager desde el contenedor de servicios de Laravel
            if (function_exists('app')) {
                try {
                    return $cached = app('Intervention\\Image\\Interfaces\\ImageManagerInterface');
                } catch (Throwable) {
                    // Si falla, cae a la instanciación directa
                }
            }

            // Intenta instanciar directamente con el driver Imagick si está disponible
            if (class_exists('Intervention\\Image\\Drivers\\Imagick\\Driver')) {
                $driver = new \Intervention\Image\Drivers\Imagick\Driver();
                return $cached = new ImageManager($driver);
            }

            // Intenta instanciar directamente con el driver GD si está disponible
            if (class_exists('Intervention\\Image\\Drivers\\Gd\\Driver')) {
                $driver = new \Intervention\Image\Drivers\Gd\Driver();
                self::applyGdMemoryLimit(); // Aplica límite de memoria para GD
                return $cached = new ImageManager($driver);
            }

            // Si las clases específicas no existen, intenta con el constructor de string
            $driverString = extension_loaded('imagick') ? 'imagick' : 'gd';
            if ($driverString === 'gd') {
                self::applyGdMemoryLimit(); // Aplica límite de memoria para GD
            }
            return $cached = new ImageManager($driverString);
        }

        // Si no se encuentra ninguna versión compatible de Intervention Image
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

        // Obtiene la ruta real del archivo subido
        $path = $value->getRealPath();
        if (!is_string($path) || $path === '') {
            $fail(__('validation.custom.image.invalid_file'));
            return;
        }

        // Escanea únicamente los primeros bytes desde disco para minimizar uso de memoria
        if (! $this->scanFileForSuspiciousPayload($path, $value->getClientOriginalName())) {
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

        // Comprueba la extensión del archivo
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '' || !in_array($extension, $this->constraints->allowedExtensions(), true)) {
            return false;
        }

        // Extrae metadatos básicos de la imagen directamente desde el archivo en disco
        $imageInfo = null;
        set_error_handler(static function (): bool {
            // Suprime errores de getimagesize y permite continuar
            return true;
        });
        try {
            $imageInfo = getimagesize($path);
        } finally {
            // Restaura el manejador de errores original
            restore_error_handler();
        }
        if (!$imageInfo) {
            return false;
        }

        // Almacena las dimensiones detectadas para uso posterior
        $width  = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $this->detectedDimensions = [$width, $height];

        $maxMegapixels = max(0.0, (float) $this->constraints->maxMegapixels);
        if (!$this->validateDimensionsAndBombs($width, $height, $size, $maxMegapixels)) {
            return false;
        }

        $originalFilename = $file->getClientOriginalName();

        // Determina el MIME confiable del archivo
        $detectedMime = $this->resolveTrustedMime($path, $imageInfo, $originalFilename);
        if ($detectedMime === null) {
            return false;
        }

        // Comprueba que la extensión coincida con el MIME detectado
        if (!$this->mimeMatchesExtension($extension, $detectedMime, $originalFilename)) {
            return false;
        }

        // Comprueba si la imagen es animada (si está prohibido)
        if ($this->isAnimatedImage($detectedMime, $path)) {
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
                $normalized = $this->reencodeToSafeBytes($this->decodedImage, $detectedMime);
            } catch (Throwable $e) {
                $this->logWarning('image_normalize_failed', $file->getClientOriginalName(), $e);
                return false;
            }

            if (!is_string($normalized) || $normalized === '') {
                return false;
            }

            // Vuelve a escanear el contenido normalizado en busca de payloads sospechosos
            if (!$this->scanContentForSuspiciousPayload($normalized, $file->getClientOriginalName())) {
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
            if (!$this->validateDimensionsAndBombs($nW, $nH, $nSize, $maxMegapixels)) {
                return false;
            }

            if (! $this->overwriteFileWithBytes($path, $normalized)) {
                $this->logWarning('image_normalize_write_failed', $file->getClientOriginalName());
                return false;
            }

            // Actualiza el estado interno para reflejar el binario normalizado
            $this->detectedDimensions = [$nW, $nH];
            $size = $nSize;
        }

        return true;
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
     * Determina el MIME confiable combinando resultados de EXIF y finfo.
     *
     * Esta función obtiene el MIME mediante getimagesize/EXIF y finfo_file,
     * confrontando ambos resultados para elevar la confianza. Cuando difieren
     * pero ambos son válidos se prioriza finfo y se registra la discrepancia
     * en lugar de rechazar automáticamente.
     *
     * @param string      $path             Ruta absoluta del archivo en disco.
     * @param array|null  $imageInfo        Información opcional obtenida previamente con getimagesize().
     * @param string|null $originalFilename Nombre original para trazabilidad en logs.
     * @return string|null El MIME confiable o null si no se puede determinar o es inválido.
     */
    private function resolveTrustedMime(string $path, ?array $imageInfo = null, ?string $originalFilename = null): ?string
    {
        $exifMime = $this->detectExifMime($path, $imageInfo);
        $finfoMime = $this->detectFinfoMime($path);

        $exifAllowed = $exifMime !== null && $this->isMimeAllowed($exifMime);
        $finfoAllowed = $finfoMime !== null && $this->isMimeAllowed($finfoMime);

        if ($exifMime !== null && !$exifAllowed) {
            $this->logWarning('image_mime_exif_rejected', $originalFilename ?? 'unknown', [
                'mime' => $exifMime,
            ]);
            $exifMime = null;
        }

        if ($finfoMime !== null && !$finfoAllowed) {
            $this->logWarning('image_mime_finfo_rejected', $originalFilename ?? 'unknown', [
                'mime' => $finfoMime,
            ]);
            $finfoMime = null;
        }

        if ($finfoMime !== null && $exifMime !== null && $finfoMime !== $exifMime) {
            $this->logWarning('image_mime_detector_mismatch', $originalFilename ?? 'unknown', [
                'exif_mime' => $exifMime,
                'finfo_mime' => $finfoMime,
                'trusted_source' => 'finfo',
            ]);

            return $finfoMime;
        }

        return $finfoMime ?? $exifMime;
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
        $normalizedMime = self::normalizeMime($mime);
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
     * Detecta el MIME usando la información EXIF/getimagesize.
     *
     * @param string $path Ruta del archivo en disco.
     * @param array|null $imageInfo Información opcional obtenida previamente.
     * @return string|null El MIME detectado o null si falla.
     */
    private function detectExifMime(string $path, ?array $imageInfo = null): ?string
    {
        $info = $imageInfo;
        if ($info === null) {
            $info = @getimagesize($path);
        }

        if (!is_array($info) || !isset($info[2])) {
            return null;
        }

        // Convierte el índice de tipo de imagen a MIME
        $mime = image_type_to_mime_type((int) $info[2]);

        return self::normalizeMime($mime);
    }

    /**
     * Detecta el MIME usando la extensión fileinfo de PHP sobre disco.
     *
     * @param string $path Ruta del archivo en disco.
     * @return string|null El MIME detectado o null si falla.
     */
    private function detectFinfoMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            // Analiza el archivo directamente para obtener el MIME
            $mime = finfo_file($finfo, $path);
        } finally {
            // Cierra el recurso de finfo
            finfo_close($finfo);
        }

        return self::normalizeMime($mime);
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
        $normalized = self::normalizeMime($mime);
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
     * Normaliza un string de MIME.
     *
     * Convierte a minúsculas, elimina espacios y parámetros adicionales (como charset).
     *
     * @param string|null $mime El MIME a normalizar.
     * @return string|null El MIME normalizado o null si es inválido.
     */
    private static function normalizeMime(?string $mime): ?string
    {
        if (!is_string($mime)) {
            return null;
        }

        $normalized = strtolower(trim($mime));
        if ($normalized === '') {
            return null;
        }

        // Elimina parámetros adicionales después de ';'
        $semicolon = strpos($normalized, ';');
        if ($semicolon !== false) {
            $normalized = substr($normalized, 0, $semicolon);
        }

        if (isset(self::MIME_ALIAS_MAP[$normalized])) {
            $normalized = self::MIME_ALIAS_MAP[$normalized];
        }

        return $normalized;
    }

    /**
     * Verifica si una imagen es animada (solo GIF y WebP actualmente).
     *
     * @param string $mime El MIME detectado de la imagen.
     * @param string $path Ruta de la imagen en disco.
     * @return bool True si la imagen es animada.
     */
    private function isAnimatedImage(string $mime, string $path): bool
    {
        $normalized = self::normalizeMime($mime);
        if ($normalized === null) {
            return false;
        }

        // Solo comprueba animación para tipos de imagen relevantes
        return match ($normalized) {
            'image/webp' => $this->isAnimatedWebp($path),
            'image/gif'  => $this->isAnimatedGif($path),
            default      => false,
        };
    }

    /**
     * Comprueba si un archivo GIF es animado leyendo el stream desde disco.
     */
    private function isAnimatedGif(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 6);
            if (!is_string($header) || !str_starts_with($header, 'GIF')) {
                return false;
            }

            $descriptorCount = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $descriptorCount += substr_count($chunk, "\x2C"); // Descriptor de imagen
                if ($descriptorCount > 1) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /**
     * Comprueba si un archivo WebP es animado sin cargarlo completo en memoria.
     */
    private function isAnimatedWebp(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 16);
            if (!is_string($header) || strlen($header) < 16) {
                return false;
            }

            if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
                return false;
            }

            // Sitúa el puntero justo después del encabezado RIFF/WEBP
            if (fseek($handle, 12, SEEK_SET) !== 0) {
                return false;
            }

            while (!feof($handle)) {
                $chunkHeader = fread($handle, 8);
                if (!is_string($chunkHeader) || strlen($chunkHeader) < 8) {
                    break;
                }

                $chunkId = substr($chunkHeader, 0, 4);
                $chunkSizeData = substr($chunkHeader, 4, 4);
                $chunkSize = unpack('V', $chunkSizeData)[1] ?? 0;

                if ($chunkId === 'VP8X') {
                    $chunkData = fread($handle, $chunkSize);
                    if (!is_string($chunkData) || strlen($chunkData) < 1) {
                        break;
                    }

                    $flags = ord($chunkData[0]);
                    if ($flags & 0x02) {
                        return true;
                    }
                } elseif ($chunkId === 'ANIM') {
                    return true;
                } else {
                    if (fseek($handle, $chunkSize, SEEK_CUR) === -1) {
                        break;
                    }
                }

                // Padding a byte si el chunk tiene tamaño impar
                if ($chunkSize % 2 === 1 && fseek($handle, 1, SEEK_CUR) === -1) {
                    break;
                }
            }
        } finally {
            fclose($handle);
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
    private function validateDimensionsAndBombs(int $width, int $height, int $size, float $maxMegapixels): bool
    {
        $minDim = $this->constraints->minDimension;
        $maxDim = $this->constraints->maxDimension;

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        if ($width < $minDim || $width > $maxDim || $height < $minDim || $height > $maxDim) {
            return false;
        }

        $uncompressed = (float) $width * (float) $height * 4.0;
        $ratio = $size > 0 ? $uncompressed / (float) $size : INF;
        if ($ratio > (float) $this->bombRatioThreshold) {
            return false;
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
     * Re-encode a normalized image to safe bytes, attempting to strip metadata.
     * Tries Intervention v2 encode()/stream() and v3 toPng()/toJpeg() best-effort.
     *
     * Esta función intenta re-codificar la imagen decodificada para producir
     * un binario limpio y seguro, potencialmente eliminando metadatos o código malicioso oculto.
     *
     * @param object $image El objeto de imagen decodificado.
     * @param string|null $preferredMime El tipo MIME preferido para la salida.
     * @return string|null El contenido binario de la imagen normalizada o null si falla.
     */
    private function reencodeToSafeBytes(object $image, ?string $preferredMime = null): ?string
    {
        $format = $this->chooseSafeFormat($preferredMime);

        // Intenta usar los métodos específicos de Intervention v3 (toPng, toJpeg, etc.)
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
                $res = $image->{$method}(90); // Asume calidad 90 como ejemplo
                $bytes = $this->stringifyEncoded($res);
                if (is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }
            } catch (Throwable) {
                // Si falla, intenta el fallback
            }
        }

        // Último recurso: guardar temporalmente el objeto codificado y leerlo
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

    /**
     * Sustituye el contenido del archivo original por la versión normalizada.
     *
     * El proceso se realiza de forma atómica:
     * 1. Crea un fichero temporal en el mismo directorio del destino.
     * 2. Escribe los bytes proporcionados en dicho temporal con bloqueo exclusivo.
     * 3. Aplica permisos 0600 sobre el temporal.
     * 4. Renombra el temporal sobre el fichero original.
     *
     * Si cualquier paso falla se elimina el temporal y se devuelve false.
     *
     * @param string $path  Ruta completa al fichero que será sobrescrito.
     * @param string $bytes Contenido binario que se escribirá.
     *
     * @return bool true si la sustitución fue exitosa, false en caso contrario.
     */
    private function overwriteFileWithBytes(string $path, string $bytes): bool
    {
        $directory = dirname($path);
        if ($directory === '' || $directory === '.') {
            $directory = sys_get_temp_dir();
        }

        $tempPath = tempnam($directory, 'sec_norm_');
        if ($tempPath === false) {
            return false;
        }

        if (file_put_contents($tempPath, $bytes, LOCK_EX) === false) {
            @unlink($tempPath);
            return false;
        }

        @chmod($tempPath, 0600);

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            return false;
        }

        return true;
    }

    /**
     * Decide el formato seguro para la normalización.
     *
     * @param string|null $preferredMime El MIME original de la imagen.
     * @return string El formato elegido (e.g., 'jpeg', 'png').
     */
    private function chooseSafeFormat(?string $preferredMime): string
    {
        // Mantiene JPEG si es origen JPEG (para evitar pérdidas innecesarias), si no PNG lossless
        if (is_string($preferredMime) && str_contains($preferredMime, 'jpeg')) {
            return 'jpeg';
        }
        return 'png';
    }

    /**
     * Intenta convertir objetos de encode/stream/encoded a string binario.
     *
     * Esta función maneja las diferentes formas en que Intervention puede devolver
     * los datos codificados (como objetos con métodos toString, getEncoded, etc.).
     *
     * @param mixed $encoded El resultado del proceso de codificación.
     * @return string|null El contenido binario como string o null si falla.
     */
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

    /**
     * Escanea los primeros bytes del archivo directamente desde disco para detectar payloads sospechosos.
     *
     * Abre el fichero en modo binario, lee los primeros self::SCAN_BYTES y
     * delega el análisis real a scanContentForSuspiciousPayload().
     *
     * Si el fichero no puede abrirse o estar vacío se registra un warning y se
     * devuelve false.
     *
     * @param string      $path             Ruta completa al fichero que se va a inspeccionar.
     * @param string|null $originalFilename Nombre original del fichero (para logs).
     *
     * @return bool true si no se detecta nada sospechoso, false en caso contrario
     *              o si el fichero es inaccesible.
     */
    private function scanFileForSuspiciousPayload(string $path, ?string $originalFilename = null): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->logWarning('image_scan_failed', $originalFilename ?? 'unknown', ['reason' => 'unreadable']);
            return false;
        }

        try {
            $buffer = fread($handle, self::SCAN_BYTES);
            if ($buffer === false || $buffer === '') {
                return false;
            }

            return $this->scanContentForSuspiciousPayload($buffer, $originalFilename);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Aplica heurísticas de payload sospechoso sobre el contenido en memoria.
     *
     * Escanea los primeros bytes del contenido del archivo en busca de patrones
     * conocidos que puedan indicar código malicioso incrustado.
     *
     * @param string      $content           Contenido binario ya leído.
     * @param string|null $originalFilename  Nombre original (solo para auditoría anonimizada).
     * @return bool True si no se encuentran patrones sospechosos.
     */
    private function scanContentForSuspiciousPayload(string $content, ?string $originalFilename = null): bool
    {
        // Limita el escaneo a los primeros N bytes
        $buffer = substr($content, 0, self::SCAN_BYTES);

        foreach ($this->suspiciousPayloadPatterns() as $pattern) {
            if (preg_match($pattern, $buffer)) {
                $this->logWarning('image_suspicious_payload', $originalFilename ?? 'unknown', ['pattern' => $pattern]);
                return false;
            }
        }
        return true;
    }

    /**
     * Patrón heurístico para detectar payloads sospechosos dentro de binarios de imagen.
     *
     * @return list<non-empty-string> Una lista de patrones de expresión regular.
     */
    private function suspiciousPayloadPatterns(): array
    {
        // Intenta obtener los patrones desde la configuración
        $patterns = config('image-pipeline.suspicious_payload_patterns');

        if (!is_array($patterns) || $patterns === []) {
            // Devuelve patrones predeterminados si no hay configuración
            return [
                '/<\?php/i', // Inicio de script PHP
                '/<\?=/i',  // Inicio de script PHP con echo
                '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s{0,100}\(/i', // Funciones de ejecución
                '/base64_decode\s{0,100}\(/i', // Decodificación base64
            ];
        }

        // Procesa y filtra los patrones de la configuración
        return array_values(
            array_filter(
                array_map(static function ($pattern) {
                    // Si es un string directo
                    if (is_string($pattern) && $pattern !== '') {
                        return $pattern;
                    }

                    // Si es un array con clave 'pattern'
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
        // Intenta obtener el ID del usuario desde el contexto de validación o la solicitud actual
        $userId = Arr::get($this->data, 'user_id');

        if ($userId === null && function_exists('request')) {
            try {
                $user = request()->user();

                if ($user !== null) {
                    $userId = $user->getAuthIdentifier();
                }
            } catch (Throwable $ignored) {
                // Ignora errores si no hay contexto HTTP disponible.
            }
        }

        // Prepara el contexto del log
        $contextArray = [
            'event' => $event,
            // Se almacena un hash del nombre del archivo para anonimizarlo
            'file_hash' => hash('sha256', (string) $filename),
            'user_id' => $userId,
        ];

        // Añade detalles del error si se proporciona un Throwable
        if ($context instanceof Throwable) {
            $contextArray['exception'] = $context->getMessage();
        } elseif (is_array($context)) {
            // Mezcla el contexto adicional
            $contextArray = array_merge($contextArray, $context);
        }

        // Registra la advertencia
        Log::warning('secure_image_validation', $contextArray);
    }
}
