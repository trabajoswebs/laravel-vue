<?php

declare(strict_types=1);

/**
 * Configuración del pipeline de procesamiento de imágenes.
 *
 * Este archivo define todas las opciones de configuración para el procesamiento,
 * validación, optimización y seguridad de imágenes subidas en la aplicación.
 * Muchas de estas opciones pueden ser personalizadas mediante variables de entorno.
 *
 * @package App\Config
 * @author  [Tu Nombre o Equipo]
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Límites básicos
    |--------------------------------------------------------------------------
    |
    | Controlan tamaño y resolución máximos aceptados/producidos por la pipeline.
    | Usa ENV para personalizarlos por entorno.
    |
    */

    // Tamaño máximo del archivo de entrada (bytes)
    'max_bytes' => env('IMG_MAX_BYTES', 15 * 1024 * 1024), // 15MB

    // Umbral de ratio para detectar image bombs (descompresión vs tamaño en disco)
    'bomb_ratio_threshold' => env('IMG_BOMB_RATIO', 100),

    // Extensiones de imagen permitidas (se normalizan a minúsculas)
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
        'svg', // Puede contener código malicioso
        'svgz', // Versión comprimida de SVG
        'zip', // Archivo comprimido
    ],

    // Tipos MIME explícitamente prohibidos
    'disallowed_mimes' => [
        'image/svg+xml', // Puede contener código malicioso
        'application/zip', // Archivo comprimido
        'application/x-zip-compressed', // Archivo comprimido
    ],

    // Configuración de normalización de imágenes
    'normalization' => [
        // Habilita la normalización de imágenes de entrada
        'enabled' => env('IMG_NORMALIZE', true),
    ],

    // Rate limiting para subidas (protege los escaneos costosos).
    'rate_limit' => [
        'max_attempts' => env('IMG_RATE_MAX', 10),
        'decay_seconds' => env('IMG_RATE_DECAY', 60),
    ],

    // Límites de recursos para las bibliotecas de procesamiento de imágenes
    'resource_limits' => [
        'imagick' => [
            // Memoria máxima en MB para Imagick
            'memory_mb' => env('IMG_IMAGICK_MEMORY_MB', 128),
            // Memoria virtual máxima en MB para Imagick
            'map_mb'    => env('IMG_IMAGICK_MAP_MB', 256),
            // Tiempo máximo de ejecución en ms para operaciones de Imagick
            'time_ms'   => env('IMG_IMAGICK_TIME_MS', 750),
        ],
        // Memoria máxima en MB para operaciones de GD (0 = ilimitada)
        'gd_memory_mb' => env('IMG_GD_MEMORY_MB', 0),
    ],

    // Configuración del escaneo de seguridad de archivos
    'scan' => [
        // Tamaño del chunk para escaneo por partes (bytes)
        'chunk_bytes' => env('IMG_SCAN_CHUNK_BYTES', 256 * 1024),
        // Tamaño máximo del archivo para escaneo (bytes)
        'max_bytes'   => env('IMG_SCAN_MAX_BYTES', 4 * 1024 * 1024),
        // Modo estricto para el escaneo (puede rechazar más archivos)
        'strict'      => filter_var(env('IMG_SCAN_STRICT', false), FILTER_VALIDATE_BOOLEAN),
        // Modo estricto para debugging del escaneo
        'debug_strict'=> filter_var(env('IMG_SCAN_DEBUG_STRICT', false), FILTER_VALIDATE_BOOLEAN),
        // Directorio base permitido para escaneo (previene path traversal)
        'allowed_base_path' => value(function () {
            $default = sys_get_temp_dir();
            $envPath = env('IMG_SCAN_ALLOWED_BASE', $default);

            if (!is_string($envPath) || $envPath === '') {
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            $normalized = rtrim($envPath, DIRECTORY_SEPARATOR);
            if ($normalized === '') {
                $normalized = DIRECTORY_SEPARATOR;
            }

            $dangerous = [
                DIRECTORY_SEPARATOR, // Raíz del sistema
                '/etc', '/var', '/usr', '/root', '/home', '/opt', // Directorios sensibles
                '/proc', '/sys', '/dev', '/run', // Directorios virtuales
            ];

            foreach ($dangerous as $danger) {
                $dangerNorm = rtrim($danger, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
                if ($normalized === $dangerNorm || str_starts_with($normalized . DIRECTORY_SEPARATOR, $dangerNorm . DIRECTORY_SEPARATOR)) {
                    // Si el path configurado es peligroso, se usa el predeterminado.
                    return rtrim($default, DIRECTORY_SEPARATOR);
                }
            }

            if (!is_dir($envPath) || is_link($envPath)) {
                // Si no es un directorio o es un enlace simbólico, se usa el predeterminado.
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            return $normalized;
        }),
        // Directorio base donde se almacenan las reglas de escaneo (YARA)
        'allowed_rules_base_path' => value(function () {
            $default = base_path('security/yara');
            $envPath = env('IMG_SCAN_RULES_BASE', $default);

            if (!is_string($envPath) || $envPath === '') {
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            if (!is_dir($envPath) || is_link($envPath)) {
                // Si no es un directorio o es un enlace simbólico, se usa el predeterminado.
                return rtrim($default, DIRECTORY_SEPARATOR);
            }

            return rtrim($envPath, DIRECTORY_SEPARATOR);
        }),
        // Lista blanca de binarios ejecutables permitidos para escaneo
        'bin_allowlist' => value(function () {
            $candidates = [
                env('IMG_SCAN_CLAMAV_BIN', '/usr/bin/clamdscan'),
                env('IMG_SCAN_YARA_BIN', '/usr/bin/yara'),
            ];

            $envList = env('IMG_SCAN_BIN_ALLOWLIST');
            if (is_string($envList) && $envList !== '') {
                $candidates = array_merge($candidates, preg_split('/[,\s;]+/', $envList) ?: []);
            }

            $normalized = [];
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
        // Tamaño máximo del archivo para escaneo individual (bytes)
        'max_file_size_bytes' => env('IMG_SCAN_MAX_FILE_SIZE', 20 * 1024 * 1024),
        // Tiempo de espera inactivo para escaneo (segundos)
        'idle_timeout' => env('IMG_SCAN_IDLE_TIMEOUT', 10),
        // Tamaño máximo de las reglas de escaneo (bytes)
        'rules_max_bytes' => env('IMG_SCAN_RULES_MAX_BYTES', 2 * 1024 * 1024),
        // Handlers de escaneo a utilizar
        'handlers'    => array_values(array_filter([
            env('IMG_SCAN_USE_CLAMAV', false) ? App\Services\Security\Scanners\ClamAvScanner::class : null,
            env('IMG_SCAN_USE_YARA', false) ? App\Services\Security\Scanners\YaraScanner::class : null,
        ], static fn ($handler) => is_string($handler) && $handler !== '')),
        // Configuración específica para ClamAV
        'clamav' => [
            'binary'   => env('IMG_SCAN_CLAMAV_BIN', '/usr/bin/clamdscan'),
            'timeout'  => env('IMG_SCAN_CLAMAV_TIMEOUT', 10),
            'arguments'=> env('IMG_SCAN_CLAMAV_ARGS', '--no-summary --fdpass'),
        ],
        // Configuración específica para YARA
        'yara' => [
            'binary'    => env('IMG_SCAN_YARA_BIN', '/usr/bin/yara'),
            'rules_path'=> env('IMG_SCAN_YARA_RULES', base_path('security/yara/images.yar')),
            'timeout'   => env('IMG_SCAN_YARA_TIMEOUT', 5),
            'arguments' => env('IMG_SCAN_YARA_ARGS', '--fail-on-warnings --nothreads'),
        ],
    ],

    // Dimensión mínima (ancho y alto) requerida para aceptar la imagen (en píxeles)
    'min_dimension' => env('IMG_MIN_DIMENSION', 128), // px

    // Límite de megapíxeles admitidos (protección DoS)
    'max_megapixels' => env('IMG_MAX_MEGAPIXELS', 48.0),

    // Lado mayor máximo del resultado (resize manteniendo proporción) (en píxeles)
    'max_edge' => env('IMG_MAX_EDGE', 16384), // px



    /*
    |--------------------------------------------------------------------------
    | Calidad y formatos de salida
    |--------------------------------------------------------------------------
    |
    | Ajustes de re-encode. Si la imagen tiene canal alpha y ALPHA_TO_WEBP=true,
    | se forzará WebP para preservar transparencia con buen ratio.
    |
    */

    // JPEG: calidad 0–100 (82 recomendado)
    'jpeg_quality' => env('IMG_JPEG_QUALITY', 82),

    // Si la imagen tiene transparencia, ¿forzar WebP?
    'alpha_to_webp' => env('IMG_ALPHA_TO_WEBP', true),

    // Umbral a partir del cual activar JPEG progresivo (en píxeles, lado mayor)
    'jpeg_progressive_min' => env('IMG_JPEG_PROGRESSIVE_MIN', 1200), // px

    // Método de WebP (0–6). 6 = más calidad/tiempo
    'webp_method' => env('IMG_WEBP_METHOD', 6),

    // Patrón de firmas sospechosas dentro de binarios de imagen (regex). Ajustable por entorno.
    'suspicious_payload_patterns' => [
        '/<\?php/i', // Código PHP
        '/<\?=/i',  // Código PHP corto
        '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s*\(/i', // Funciones peligrosas
        '/base64_decode\s*\(/i', // Decodificación de base64
    ],

    // Preferencia por defecto para encolar conversions (true = queued, false = sync)
    'queue_conversions_default' => env('IMG_QUEUE_CONVERSIONS_DEFAULT', true),



    /*
    |--------------------------------------------------------------------------
    | PNG tuning (sin pérdida)
    |--------------------------------------------------------------------------
    */

    // Nivel de compresión PNG (0–9)
    'png_compression_level' => env('IMG_PNG_COMPRESSION_LEVEL', 9),

    // Estrategia de compresión PNG (0–4)
    'png_compression_strategy' => env('IMG_PNG_COMPRESSION_STRATEGY', 1),

    // Filtro de compresión PNG (0–5)
    'png_compression_filter' => env('IMG_PNG_COMPRESSION_FILTER', 5),

    // Excluir chunks PNG innecesarios (reduce metadatos)
    'png_exclude_chunk' => env('IMG_PNG_EXCLUDE_CHUNK', 'all'),



    /*
    |--------------------------------------------------------------------------
    | GIF animados
    |--------------------------------------------------------------------------
    |
    | Si no preservas animación, se tomará el primer frame y se convertirá
    | a PNG/JPEG según transparencia. Si preservas, ojo con rendimiento.
    |
    */

    // ¿Preservar animación GIF?
    'preserve_gif_animation' => env('IMG_PRESERVE_GIF_ANIMATION', false),

    // Límite de frames a procesar cuando preserve_gif_animation=true
    'max_gif_frames' => env('IMG_MAX_GIF_FRAMES', 60),

    // Filtro de resize para GIF animado (Imagick filter constant)
    // Valores típicos: POINT=6, BOX=7, TRIANGLE=8, HERMITE=9, HANNING=10, HAMMING=11, BLACKMAN=12, GAUSSIAN=13, QUADRATIC=14, CUBIC=15, CATROM=16, LANCZOS=22
    'gif_resize_filter' => env('IMG_GIF_RESIZE_FILTER', 8), // TRIANGLE por rendimiento



    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Control de verbosidad/etiquetas para troubleshooting.
    |
    */

    // Canal o stack de logging (usa null para el default)
    'log_channel' => env('IMG_LOG_CHANNEL', null),

    // Nivel de detalle para logs de depuración internos
    'debug' => env('IMG_DEBUG', false),


    /*---------------------------------------*/

    // Tamaños predefinidos para la colección de avatares
    'avatar_sizes' => [
        'thumb'  => 128,
        'medium' => 256,
        'large'  => 512,
    ],

    // Calidad recomendada para WEBP (0-100)
    // Homologado a IMG_WEBP_QUALITY; mantiene compatibilidad con IMG_WEBP_QUALITY
    'webp_quality' => env('IMG_WEBP_QUALITY', env('IMG_WEBP_QUALITY', 75)),

    // Preferencia específica para la colección avatar (null = usa queue_conversions_default)
    'avatar_queue_conversions' => env('IMG_AVATAR_QUEUE_CONVERSIONS', null),

    // Disco donde se almacenará la colección de avatar
    // Usa env AVATAR_DISK o cae al disco por defecto
    'avatar_disk' => env('AVATAR_DISK', env('FILESYSTEM_DISK', 'local')),

    // Nombre de la colección de avatar
    'avatar_collection' => env('AVATAR_COLLECTION', 'avatar'),

    /*
    |--------------------------------------------------------------------------
    | Galería (opcional)
    |--------------------------------------------------------------------------
    |
    | Colección/disco y tamaños por defecto para imágenes de galería/portfolio.
    | Puedes ajustar estos valores por entorno o sobrescribir sizes por modelo.
    |
    */
    // Disco donde se almacenará la colección de galería
    'gallery_disk' => env('GALLERY_DISK', env('FILESYSTEM_DISK', 'local')),
    // Nombre de la colección de galería
    'gallery_collection' => env('GALLERY_COLLECTION', 'gallery'),
    // Tamaños predefinidos para la colección de galería
    'gallery_sizes' => [
        'thumb'  => 320,
        'medium' => 1280,
        'large'  => 2048,
    ],

    /*
    |--------------------------------------------------------------------------
    | Postproceso: colecciones a procesar
    |--------------------------------------------------------------------------
    |
    | Coma/space-separated list o array de colecciones para el listener que
    | encola la optimización tras las conversions (default: avatar,gallery).
    |
    */
    // Lista de colecciones para las cuales se ejecutará el postproceso de optimización
    'postprocess_collections' => env('IMG_POSTPROCESS_COLLECTIONS', 'avatar,gallery'),
];
