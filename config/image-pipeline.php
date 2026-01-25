<?php

declare(strict_types=1);

/**
 * Image Processing Pipeline Configuration
 *
 * This file defines all configuration options for image processing,
 * validation, optimization, and security in the application.
 * Many options can be customized through environment variables.
 *
 * @package App\Config
 */

return [
    // Selección explícita de driver de Intervention (compatibilidad con IMAGE_DRIVER legado).
    'driver' => env('IMG_DRIVER', env('IMAGE_DRIVER', 'gd')),

    'imagick' => [
        // Requiere confirmación de política de seguridad para Imagick
        'require_policy_confirmation' => (bool) env('IMG_IMAGICK_REQUIRE_POLICY', true),

        // Ruta al archivo policy.xml endurecido para seguridad
        'policy_path' => env('IMG_IMAGICK_POLICY_PATH'), // Debe apuntar al policy.xml endurecido

        // Límites de recursos para Imagick para prevenir DoS
        'resource_limits' => [
            'memory' => (int) env('IMG_IMAGICK_MEMORY_LIMIT', 256 * 1024 * 1024), // Límite de memoria en bytes
            'map' => (int) env('IMG_IMAGICK_MAP_LIMIT', 512 * 1024 * 1024),       // Límite de mapeo de memoria
            'area' => (int) env('IMG_IMAGICK_AREA_LIMIT', 128 * 1024 * 1024),     // Límite de área de imagen
            'file' => (int) env('IMG_IMAGICK_FILE_HANDLES', 32),                 // Límite de handles de archivo
            'time' => (int) env('IMG_IMAGICK_TIME_LIMIT', 60),                   // Límite de tiempo en segundos
        ],
    ],

    // =========================================================================
    // 🔹 BASIC LIMITS & VALIDATION
    // =========================================================================

    /*
    |--------------------------------------------------------------------------
    | Basic File Limits
    |--------------------------------------------------------------------------
    |
    | Controles de tamaño y resolución aceptados/producidos por el pipeline.
    | Todos los valores son en bytes o píxeles y se pueden ajustar por ENV.
    |
    | IMPORTANTE: aquí hay **tres** niveles distintos de límite:
    |
    | 1) max_bytes
    |    - Límite de "tubería" a bajo nivel.
    |    - Protege la etapa de decodificación/normalización frente a imágenes
    |      demasiado grandes (DoS, image bombs, etc.).
    |
    | 2) max_upload_size
    |    - Límite lógico de la subida de imagen en dominio.
    |    - Lo usa DefaultUploadService (MediaUploader) para rechazar
    |      el archivo ANTES de copiar a cuarentena / pipeline.
    |    - Es el tamaño máximo que tu aplicación promete aceptar.
    |
    | 3) quarantine_max_size
    |    - Límite duro específico de la cuarentena (LocalQuarantineRepository).
    |    - Ningún artefacto almacenado en cuarentena puede superar este tamaño.
    |    - Normalmente debería ser >= max_upload_size.
    |
    | Recomendación típica:
    |   IMG_MAX_BYTES           ≈ IMG_MAX_UPLOAD_SIZE
    |   IMG_QUARANTINE_MAX_SIZE ≈ IMG_MAX_UPLOAD_SIZE
    |
    | Y coherente con php.ini:
    |   upload_max_filesize, post_max_size, límites de Nginx/Apache, etc.
    |
    */

    // Máximo absoluto que el pipeline intentará procesar (bytes).
    // Protege la decodificación / normalización a bajo nivel.
    'max_bytes' => (int) env('IMG_MAX_BYTES', 25 * 1024 * 1024), // 25MB

    // Validación estricta de magic bytes y polyglots
    'enforce_strict_magic_bytes' => env('IMG_ENFORCE_MAGIC_BYTES', true),
    'allowed_magic_signatures' => [
        'ffd8ff' => 'image/jpeg',
        '89504e470d0a1a0a' => 'image/png',
        '47494638' => 'image/gif',
        '000000206674797061766966' => 'image/avif',
        '0000001c6674797061766966' => 'image/avif',
        '52494646' => 'riff',
        '57454250' => 'image/webp',
        '25504446' => 'application/pdf',
        '504b0304' => 'zip',
    ],
    'prevent_polyglot_files' => env('IMG_PREVENT_POLYGLOT', true),
    'max_decompression_ratio' => env('IMG_MAX_DECOMPRESSION_RATIO', 500.0),

    // Ratio de descompresión para detección de bombas de imagen
    'bomb_ratio_threshold' => env('IMG_BOMB_RATIO', 100),

    // Dimensiones mínimas requeridas (ancho y alto) en píxeles
    'min_dimension' => env('IMG_MIN_DIMENSION', 128),

    // Máximo de megapíxeles permitidos (protección contra DoS)
    'max_megapixels' => env('IMG_MAX_MEGAPIXELS', 48.0),

    // Máxima longitud de borde para salida (mantiene proporción de aspecto)
    'max_edge' => env('IMG_MAX_EDGE', 16384),

    /*
    |--------------------------------------------------------------------------
    | Logical upload limit (domain) - max_upload_size
    |--------------------------------------------------------------------------
    |
    | Límite de tamaño para la subida de imágenes en la capa de dominio.
    |
    | - Unidad: bytes.
    | - Lo utiliza DefaultUploadService (MediaUploader) para validar
    |   el tamaño ANTES de copiar el archivo a la cuarentena.
    | - Si se supera, lanza UploadValidationException('max_size_exceeded').
    |
    | ENV:
    |   IMG_MAX_UPLOAD_SIZE (bytes)
    |
    | Ejemplo .env:
    |   IMG_MAX_UPLOAD_SIZE=26214400   # ≈ 25 MB
    |
    */
    'max_upload_size' => (int) env('IMG_MAX_UPLOAD_SIZE', 25 * 1024 * 1024), // 25 MB

    /*
    |--------------------------------------------------------------------------
    | Quarantine hard limit - quarantine_max_size
    |--------------------------------------------------------------------------
    |
    | Límite duro específico para artefactos en cuarentena.
    |
    | - Unidad: bytes.
    | - Lo debe utilizar LocalQuarantineRepository (put/putStream) para
    |   rechazar cualquier artefacto que exceda este tamaño.
    | - Lo normal es:
    |       quarantine_max_size >= max_upload_size
    |   para que nada que haya pasado la validación de subida falle luego
    |   por tamaño al entrar en cuarentena.
    |
    | ENV:
    |   IMG_QUARANTINE_MAX_SIZE (opcional)
    |   IMG_MAX_UPLOAD_SIZE      (fallback)
    |
    | Prioridad:
    |   1) IMG_QUARANTINE_MAX_SIZE
    |   2) IMG_MAX_UPLOAD_SIZE
    |   3) 25MB por defecto
    |
    | Ejemplo .env:
    |   IMG_MAX_UPLOAD_SIZE=26214400
    |   IMG_QUARANTINE_MAX_SIZE=26214400
    |
    */
    'quarantine_max_size' => (int) env(
        'IMG_QUARANTINE_MAX_SIZE',
        env('IMG_MAX_UPLOAD_SIZE', 25 * 1024 * 1024),
    ),

    // TTLs por defecto para artefactos en cuarentena (horas)
    'quarantine_pending_ttl_hours' => (int) env('IMG_QUARANTINE_TTL_HOURS', 24),
    'quarantine_failed_ttl_hours' => (int) env('IMG_QUARANTINE_FAILED_TTL_HOURS', 4),

    // Timeout máximo para persistir streams en cuarentena (segundos)
    'quarantine_stream_timeout_seconds' => (float) env('IMG_QUARANTINE_STREAM_TIMEOUT_SECONDS', 15.0),

    // Máximo de segundos permitidos para operaciones de decodificación de imagen
    'decode_timeout_seconds' => (float) env('IMG_DECODE_TIMEOUT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Allowed File Types
    |--------------------------------------------------------------------------
    |
    | Defines permitted and prohibited file extensions and MIME types.
    |
    */

    // Extensiones de imagen permitidas (normalizadas a minúsculas)
    'allowed_extensions' => [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'avif',
        'gif',
    ],

    // Tipos MIME permitidos (mapa mime => extensión sugerida)
    'allowed_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif'  => 'gif',
    ],

    // Extensiones de archivo explícitamente prohibidas
    'disallowed_extensions' => [
        'svg',  // Puede contener código malicioso
        'svgz', // Versión comprimida de SVG
        'zip',  // Archivo comprimido
    ],

    // Tipos MIME explícitamente prohibidos
    'disallowed_mimes' => [
        'image/svg+xml',                // Puede contener código malicioso
        'application/zip',              // Archivo comprimido
        'application/x-zip-compressed', // Archivo comprimido
    ],

    // =========================================================================
    // 🔹 PROCESSING & OPTIMIZATION
    // =========================================================================

    /*
    |--------------------------------------------------------------------------
    | Image Normalization
    |--------------------------------------------------------------------------
    |
    | Controls input image normalization settings.
    |
    */

    'normalization' => [
        // Habilitar normalización de imagen de entrada
        'enabled' => env('IMG_NORMALIZE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload Scanning
    |--------------------------------------------------------------------------
    |
    | Bytes scanned from the start of the file to detect suspicious payloads.
    |
    */

    // Número de bytes a escanear al analizar payloads (por defecto 50 KB)
    'scan_bytes' => env('IMG_SCAN_BYTES', 50 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Output Quality & Formats
    |--------------------------------------------------------------------------
    |
    | Re-encoding settings. If image has alpha channel and ALPHA_TO_WEBP=true,
    | WebP will be forced to preserve transparency with good ratio.
    |
    */

    // Calidad JPEG 0-100 (82 recomendado)
    'jpeg_quality' => env('IMG_JPEG_QUALITY', 82),

    // Forzar WebP para imágenes con transparencia?
    'alpha_to_webp' => env('IMG_ALPHA_TO_WEBP', true),

    // Umbral para activar JPEG progresivo (en píxeles, lado más largo)
    'jpeg_progressive_min' => env('IMG_JPEG_PROGRESSIVE_MIN', 1200),

    // Método WebP (0-6). 6 = más calidad/tiempo
    'webp_method' => env('IMG_WEBP_METHOD', 6),

    // Calidad WebP recomendada (0-100)
    'webp_quality' => env('IMG_WEBP_QUALITY', env('IMG_WEBP_QUALITY', 75)),

    /*
    |--------------------------------------------------------------------------
    | PNG Tuning (Lossless)
    |--------------------------------------------------------------------------
    */

    // Nivel de compresión PNG (0-9)
    'png_compression_level' => env('IMG_PNG_COMPRESSION_LEVEL', 9),

    // Estrategia de compresión PNG (0-4)
    'png_compression_strategy' => env('IMG_PNG_COMPRESSION_STRATEGY', 1),

    // Filtro de compresión PNG (0-5)
    'png_compression_filter' => env('IMG_PNG_COMPRESSION_FILTER', 5),

    // Excluir chunks PNG innecesarios (reduce metadatos)
    'png_exclude_chunk' => env('IMG_PNG_EXCLUDE_CHUNK', 'all'),

    /*
    |--------------------------------------------------------------------------
    | Animated GIFs
    |--------------------------------------------------------------------------
    |
    | If animation is not preserved, first frame will be taken and converted
    | to PNG/JPEG based on transparency. If preserved, watch performance.
    |
    */

    // ¿Preservar animación GIF?
    'preserve_gif_animation' => env('IMG_PRESERVE_GIF_ANIMATION', false),

    // Límite de frames cuando preserve_gif_animation=true
    'max_gif_frames' => env('IMG_MAX_GIF_FRAMES', 60),

    // Filtro de redimensión para GIF animados (constante de filtro Imagick)
    'gif_resize_filter' => env('IMG_GIF_RESIZE_FILTER', 8), // TRIANGLE para rendimiento

    // =========================================================================
    // 🔹 SECURITY & SCANNING
    // =========================================================================

    /*
    |--------------------------------------------------------------------------
    | Security Scanning Configuration
    |--------------------------------------------------------------------------
    |
    | Comprehensive security scanning settings for uploaded images.
    |
    */

    'scan' => [
        // Habilitar escaneo de seguridad
        'enabled' => value(function () {
            // Leemos el entorno directamente del APP_ENV
            $appEnv = env('APP_ENV', 'production');

            // En local/testing se permite desactivar escaneo vía VIRUS_SCANNERS (vacío/none).
            if (in_array($appEnv, ['local', 'testing'], true)) {
                $configured = trim((string) env('VIRUS_SCANNERS', 'clamav'));
                return $configured !== '' && strtolower($configured) !== 'none';
            }

            // En otros entornos, siempre activado (fail-closed por defecto).
            return true;
        }),


        // Handlers de seguridad a usar
        'handlers' => value(function () {
            $aliases = [
                'clamav'   => App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner::class,
                'clamdscan' => App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner::class,
                'yara'     => App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\YaraScanner::class,
            ];

            $raw = env('VIRUS_SCANNERS', 'clamav,yara');

            // En entornos no locales forzamos al menos ClamAV si se intenta vaciar la lista.
            $appEnv = env('APP_ENV', 'production');
            if (! in_array($appEnv, ['local', 'testing'], true)) {
                $trimmed = trim((string) $raw);
                if ($trimmed === '' || strtolower($trimmed) === 'none') {
                    $raw = 'clamav,yara';
                }
            }

            $tokens = preg_split('/[\\s,;|]+/', (string) $raw) ?: [];
            $handlers = [];

            foreach ($tokens as $token) {
                $key = strtolower(trim((string) $token));
                if ($key === '' || ! isset($aliases[$key])) {
                    continue;
                }

                $handlers[] = $aliases[$key];
            }

            $handlers = array_values(array_unique($handlers));

            return $handlers === []
                ? [App\Infrastructure\Uploads\Pipeline\Scanning\Scanners\ClamAvScanner::class]
                : $handlers;
        }),


        // Tiempo de espera para escaneo en milisegundos
        'timeout_ms' => 5000,

        // Tiempo de espera en segundos (calculado desde milisegundos)
        'timeout' => value(function () {
            $ms = (int) env('IMG_SCAN_TIMEOUT_MS', 5000);
            $ms = $ms > 0 ? $ms : 5000;
            return max(1, (int) ceil($ms / 1000));
        }),

        // Tamaño de bloque para escaneo parcial (bytes)
        'chunk_bytes' => env('IMG_SCAN_CHUNK_BYTES', 256 * 1024),

        // Tamaño máximo de archivo para escaneo (bytes)
        'max_bytes'   => env('IMG_SCAN_MAX_BYTES', 4 * 1024 * 1024),

        // Modo estricto de escaneo (puede rechazar más archivos)
        'strict'      => value(function () {
            $envValue = env('IMG_SCAN_STRICT');

            // Si la variable está definida, manda ella.
            if ($envValue !== null) {
                return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
            }

            // Fail-closed por defecto fuera de local/testing.
            $appEnv = env('APP_ENV', 'production');

            return ! in_array($appEnv, ['local', 'testing'], true);
        }),


        // Modo de depuración estricto para debugging de escaneo
        'debug_strict' => filter_var(env('IMG_SCAN_DEBUG_STRICT', false), FILTER_VALIDATE_BOOLEAN),

        // Configuración del circuit breaker
        'circuit_breaker' => [
            'max_failures'   => max(1, (int) env('IMG_SCAN_CIRCUIT_MAX_FAILS', 5)), // Máximo de fallos antes de abrir el circuito
            'cache_key'      => env('IMG_SCAN_CIRCUIT_CACHE_KEY', 'image_scan:circuit_failures'), // Clave de caché para el circuito
            'decay_seconds'  => (int) env('IMG_SCAN_CIRCUIT_TTL', 900), // Tiempo de expiración del circuito en segundos
        ],

        // Directorio base permitido para escaneo (previene path traversal)
        'allowed_base_path' => value(function () {
            $fallback = sys_get_temp_dir();
            $default = storage_path('app/private/quarantine');

            if (!is_dir($default) || is_link($default)) {
                $default = $fallback;
            }

            $envPath = env('IMG_SCAN_ALLOWED_BASE', $default);

            if (!is_string($envPath) || $envPath === '') {
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            $normalized = rtrim($envPath, DIRECTORY_SEPARATOR);
            if ($normalized === '') {
                $normalized = DIRECTORY_SEPARATOR;
            }

            $dangerous = [
                DIRECTORY_SEPARATOR, // System root
                '/etc',
                '/var',
                '/usr',
                '/root',
                '/home',
                '/opt', // Sensitive directories
                '/proc',
                '/sys',
                '/dev',
                '/run', // Virtual directories
            ];

            foreach ($dangerous as $danger) {
                $dangerNorm = rtrim($danger, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
                if ($normalized === $dangerNorm || str_starts_with($normalized . DIRECTORY_SEPARATOR, $dangerNorm . DIRECTORY_SEPARATOR)) {
                    // If configured path is dangerous, use default
                    return rtrim($default, DIRECTORY_SEPARATOR);
                }
            }

            if (!is_dir($envPath) || is_link($envPath)) {
                // If not a directory or is symlink, use default
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            return $normalized;
        }),

        // Directorio base para reglas de escaneo (YARA)
        'allowed_rules_base_path' => value(function () {
            $default = base_path('security/yara');
            $envPath = env('IMG_SCAN_RULES_BASE', $default);

            if (!is_string($envPath) || $envPath === '') {
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            if (!is_dir($envPath) || is_link($envPath)) {
                // If not a directory or is symlink, use default
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            return rtrim($envPath, DIRECTORY_SEPARATOR);
        }),

        // Allowlist de binarios ejecutables permitidos para escaneo
        'bin_allowlist' => value(function () {
            $normalized = [];
            $envList = env('IMG_SCAN_BIN_ALLOWLIST');

            if (is_string($envList) && $envList !== '') {
                $candidates = preg_split('/[,\s;]+/', $envList) ?: [];
            } else {
                $candidates = [
                    '/usr/bin/clamdscan',
                    '/usr/local/bin/clamdscan',
                    '/usr/local/bin/clamdscan-wrapper.sh',
                    '/usr/bin/clamscan',
                    '/usr/local/bin/clamscan',
                ];
            }

            $configuredBinary = env('IMG_SCAN_CLAMAV_BIN');
            if (is_string($configuredBinary) && $configuredBinary !== '') {
                $candidates[] = $configuredBinary;
            }

            foreach ($candidates as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }

                $trimmed = trim($candidate);
                if ($trimmed === '') {
                    continue;
                }

                $normalized[] = $trimmed;
            }

            return array_values(array_unique($normalized));
        }),

        // Tamaño máximo de archivo para escaneo individual (bytes)
        'max_file_size_bytes' => env('IMG_SCAN_MAX_FILE_SIZE', 20 * 1024 * 1024),

        // Tiempo de espera inactivo para escaneo (segundos)
        'idle_timeout' => env('IMG_SCAN_IDLE_TIMEOUT', 10),

        // Tamaño máximo para reglas de escaneo (bytes)
        'rules_max_bytes' => env('IMG_SCAN_RULES_MAX_BYTES', 2 * 1024 * 1024),

        // Configuración específica de ClamAV
        'clamav' => [
            'binary'   => env('IMG_SCAN_CLAMAV_BIN', '/usr/bin/clamdscan'), // Ruta al binario de ClamAV
            'binary_fallbacks' => value(function () {
                $fallbacks = [
                    '/usr/bin/clamdscan',
                    '/usr/local/bin/clamdscan',
                    '/usr/local/bin/clamdscan-wrapper.sh',
                    '/usr/bin/clamscan',
                    '/usr/local/bin/clamscan',
                ];

                $normalized = [];
                foreach ($fallbacks as $candidate) {
                    if (! is_string($candidate)) {
                        continue;
                    }

                    $trimmed = trim($candidate);
                    if ($trimmed === '') {
                        continue;
                    }

                    $normalized[] = $trimmed;
                }

                return array_values(array_unique($normalized));
            }),
            'timeout'  => env('IMG_SCAN_CLAMAV_TIMEOUT', 10), // Tiempo de espera en segundos
            'arguments' => env('IMG_SCAN_CLAMAV_ARGS', '--no-summary --fdpass'), // Argumentos para el comando
        ],

        // Configuración específica de YARA
        'yara' => [
            'binary'    => env('IMG_SCAN_YARA_BIN', '/usr/bin/yara'), // Ruta al binario de YARA
            'rules_path' => env('IMG_SCAN_YARA_RULES', base_path('security/yara/images.yar')), // Ruta a las reglas YARA
            'timeout'   => env('IMG_SCAN_YARA_TIMEOUT', 5), // Tiempo de espera en segundos
            'arguments' => env('IMG_SCAN_YARA_ARGS', '--fail-on-warnings --nothreads'), // Argumentos para el comando
            'rules_hash_file' => env('IMG_SCAN_YARA_HASH_FILE', base_path('security/yara/rules.sha256')),
            'version_file' => env('IMG_SCAN_YARA_VERSION_FILE', base_path('security/yara/VERSION')),
            'expected_hash' => env('IMG_SCAN_YARA_EXPECTED_HASH'),
        ],
    ],

    // Patrones de payload sospechosos dentro de binarios de imagen (regex)
    'suspicious_payload_patterns' => [
        '/<\?php/i', // Código PHP
        '/<\?=/i',  // Código PHP corto
        '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s*\(/i', // Funciones peligrosas
        '/base64_decode\s*\(/i', // Decodificación base64
    ],

    // =========================================================================
    // 🔹 PERFORMANCE & RESOURCES
    // =========================================================================

    /*
    |--------------------------------------------------------------------------
    | Resource Limits
    |--------------------------------------------------------------------------
    |
    | Resource limits for image processing libraries.
    |
    */

    'resource_limits' => [
        'imagick' => [
            // Memoria máxima en MB para Imagick
            'memory_mb' => env('IMG_IMAGICK_MEMORY_MB', 128),
            // Memoria virtual máxima en MB para Imagick
            'map_mb'    => env('IMG_IMAGICK_MAP_MB', 256),
            // Tiempo máximo de ejecución en ms para operaciones de Imagick
            'time_ms'   => env('IMG_IMAGICK_TIME_MS', 750),
            // Máximo de hilos de trabajo por operación de Imagick
            'threads'   => env('IMG_IMAGICK_THREADS', 2),
        ],
        // Memoria máxima en MB para operaciones GD (0 = ilimitado)
        'gd_memory_mb' => env('IMG_GD_MEMORY_MB', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting for uploads (protects expensive scans).
    |
    */

    'rate_limit' => [
        'max_attempts' => env('IMG_RATE_MAX', 10), // Máximo de intentos
        'decay_seconds' => env('IMG_RATE_DECAY', 60), // Tiempo de expiración en segundos
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Controls whether image conversions are processed synchronously or queued.
    |
    */

    // Preferencia por defecto para encolar conversiones (true = encolado, false = síncrono)
    'queue_conversions_default' => env('IMG_QUEUE_CONVERSIONS_DEFAULT', true),

    // =========================================================================
    // 🔹 COLLECTIONS & STORAGE
    // =========================================================================

    /*
    |--------------------------------------------------------------------------
    | Avatar Collection
    |--------------------------------------------------------------------------
    |
    | Configuration for avatar image collection.
    |
    */

    // Tamaños predefinidos para la colección de avatar
    'avatar_sizes' => [
        'thumb'  => 128, // Miniatura
        'medium' => 256, // Tamaño medio
        'large'  => 512, // Tamaño grande
    ],

    // Preferencia específica para la colección de avatar (null = usar queue_conversions_default)
    'avatar_queue_conversions' => env('IMG_AVATAR_QUEUE_CONVERSIONS', null),

    // Disco para almacenamiento de la colección de avatar
    'avatar_disk' => env('AVATAR_DISK', env('FILESYSTEM_DISK', 'local')),

    // Nombre de la colección de avatar
    'avatar_collection' => env('AVATAR_COLLECTION', 'avatar'),

    // TTL en horas para archivos en cuarentena de avatar
    'avatar_quarantine_ttl_hours' => (int) env(
        'IMG_AVATAR_QUARANTINE_TTL',
        env('IMG_QUARANTINE_TTL_HOURS', 24),
    ),

    // TTL en horas para archivos fallidos de avatar
    'avatar_failed_ttl_hours' => (int) env(
        'IMG_AVATAR_FAILED_TTL',
        env('IMG_QUARANTINE_FAILED_TTL_HOURS', 4),
    ),

    /*
    |--------------------------------------------------------------------------
    | Gallery Collection (Optional)
    |--------------------------------------------------------------------------
    |
    | Default collection/disk and sizes for gallery/portfolio images.
    | You can adjust these values per environment or override sizes per model.
    |
    */

    // Disco para almacenamiento de la colección de galería
    'gallery_disk' => env('GALLERY_DISK', env('FILESYSTEM_DISK', 'local')),

    // Nombre de la colección de galería
    'gallery_collection' => env('GALLERY_COLLECTION', 'gallery'),

    // Tamaños predefinidos para la colección de galería
    'gallery_sizes' => [
        'thumb'  => 320,  // Miniatura
        'medium' => 1280, // Tamaño medio
        'large'  => 2048, // Tamaño grande
    ],

    // TTL en horas para archivos en cuarentena de galería
    'gallery_quarantine_ttl_hours' => (int) env(
        'IMG_GALLERY_QUARANTINE_TTL',
        env('IMG_QUARANTINE_TTL_HOURS', 24),
    ),

    // TTL en horas para archivos fallidos de galería
    'gallery_failed_ttl_hours' => (int) env(
        'IMG_GALLERY_FAILED_TTL',
        env('IMG_QUARANTINE_FAILED_TTL_HOURS', 4),
    ),

    /*
    |--------------------------------------------------------------------------
    | Post-processing Collections
    |--------------------------------------------------------------------------
    |
    | Comma/space-separated list or array of collections for the listener that
    | queues optimization after conversions (default: avatar,gallery).
    |
    */

    // Lista de colecciones para post-procesamiento de optimización
    'postprocess_collections' => env('IMG_POSTPROCESS_COLLECTIONS', 'avatar,gallery'),

    // =========================================================================
    // 🔹 LOGGING & DEBUGGING
    // =========================================================================

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Controls verbosity/tags for troubleshooting.
    |
    */

    // Canal de log o stack (usar null para valor por defecto)
    'log_channel' => env('IMG_LOG_CHANNEL', null),

    // Nivel de detalle para logs de depuración internos
    'debug' => env('IMG_DEBUG', false),
];
