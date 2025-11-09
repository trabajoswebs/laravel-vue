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
    // =========================================================================
    // 🔹 BASIC LIMITS & VALIDATION
    // =========================================================================
    
    /*
    |--------------------------------------------------------------------------
    | Basic File Limits
    |--------------------------------------------------------------------------
    |
    | Controls maximum size and resolution accepted/produced by the pipeline.
    | Use ENV to customize per environment.
    |
    */
    
    // Maximum input file size in bytes
    'max_bytes' => env('IMG_MAX_BYTES', 25 * 1024 * 1024), // 25MB
    
    // Bomb ratio threshold (decompression vs disk size)
    'bomb_ratio_threshold' => env('IMG_BOMB_RATIO', 100),
    
    // Minimum required dimension (width and height) in pixels
    'min_dimension' => env('IMG_MIN_DIMENSION', 128),
    
    // Maximum megapixels allowed (DoS protection)
    'max_megapixels' => env('IMG_MAX_MEGAPIXELS', 48.0),
    
    // Maximum edge length for output (maintains aspect ratio)
    'max_edge' => env('IMG_MAX_EDGE', 16384),
    
// Maximum upload size for validation (bytes)
'max_upload_size' => 10 * 1024 * 1024, // 10 MB

// Maximum seconds allowed for image decode operations
'decode_timeout_seconds' => (float) env('IMG_DECODE_TIMEOUT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Allowed File Types
    |--------------------------------------------------------------------------
    |
    | Defines permitted and prohibited file extensions and MIME types.
    |
    */
    
    // Allowed image extensions (normalized to lowercase)
    'allowed_extensions' => [
        'jpg',
        'jpeg', 
        'png',
        'webp',
        'avif',
        'gif',
    ],
    
    // Allowed MIME types (map mime => suggested extension)
    'allowed_mimes' => [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif'  => 'gif',
    ],
    
    // Explicitly prohibited file extensions
    'disallowed_extensions' => [
        'svg',  // May contain malicious code
        'svgz', // Compressed SVG version
        'zip',  // Compressed archive
    ],
    
    // Explicitly prohibited MIME types  
    'disallowed_mimes' => [
        'image/svg+xml', // May contain malicious code
        'application/zip', // Compressed archive
        'application/x-zip-compressed', // Compressed archive
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
        // Enable input image normalization
        'enabled' => env('IMG_NORMALIZE', true),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Output Quality & Formats
    |--------------------------------------------------------------------------
    |
    | Re-encoding settings. If image has alpha channel and ALPHA_TO_WEBP=true,
    | WebP will be forced to preserve transparency with good ratio.
    |
    */
    
    // JPEG quality 0-100 (82 recommended)
    'jpeg_quality' => env('IMG_JPEG_QUALITY', 82),
    
    // Force WebP for images with transparency?
    'alpha_to_webp' => env('IMG_ALPHA_TO_WEBP', true),
    
    // Threshold to activate progressive JPEG (in pixels, longer side)
    'jpeg_progressive_min' => env('IMG_JPEG_PROGRESSIVE_MIN', 1200),
    
    // WebP method (0-6). 6 = more quality/time
    'webp_method' => env('IMG_WEBP_METHOD', 6),
    
    // Recommended WebP quality (0-100)
    'webp_quality' => env('IMG_WEBP_QUALITY', env('IMG_WEBP_QUALITY', 75)),

    /*
    |--------------------------------------------------------------------------
    | PNG Tuning (Lossless)
    |--------------------------------------------------------------------------
    */
    
    // PNG compression level (0-9)
    'png_compression_level' => env('IMG_PNG_COMPRESSION_LEVEL', 9),
    
    // PNG compression strategy (0-4)
    'png_compression_strategy' => env('IMG_PNG_COMPRESSION_STRATEGY', 1),
    
    // PNG compression filter (0-5)
    'png_compression_filter' => env('IMG_PNG_COMPRESSION_FILTER', 5),
    
    // Exclude unnecessary PNG chunks (reduces metadata)
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
    
    // Preserve GIF animation?
    'preserve_gif_animation' => env('IMG_PRESERVE_GIF_ANIMATION', false),
    
    // Frame limit when preserve_gif_animation=true
    'max_gif_frames' => env('IMG_MAX_GIF_FRAMES', 60),
    
    // Resize filter for animated GIF (Imagick filter constant)
    'gif_resize_filter' => env('IMG_GIF_RESIZE_FILTER', 8), // TRIANGLE for performance

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
        // Enable security scanning
        'enabled' => true,
        
        // Security handlers to use
        'handlers' => [
            App\Services\Security\Scanners\ClamAvScanner::class,
            App\Services\Security\Scanners\YaraScanner::class,
        ],
        
        // Scanning timeout in milliseconds
        'timeout_ms' => 5000,
        
        // Timeout in seconds (calculated from milliseconds)
        'timeout' => value(function () {
            $ms = (int) env('IMG_SCAN_TIMEOUT_MS', 5000);
            $ms = $ms > 0 ? $ms : 5000;
            return max(1, (int) ceil($ms / 1000));
        }),
        
        // Chunk size for partial scanning (bytes)
        'chunk_bytes' => env('IMG_SCAN_CHUNK_BYTES', 256 * 1024),
        
        // Maximum file size for scanning (bytes)
        'max_bytes'   => env('IMG_SCAN_MAX_BYTES', 4 * 1024 * 1024),
        
        // Strict scanning mode (may reject more files)
        'strict'      => filter_var(env('IMG_SCAN_STRICT', false), FILTER_VALIDATE_BOOLEAN),
        
        // Debug strict mode for scanning debugging
        'debug_strict'=> filter_var(env('IMG_SCAN_DEBUG_STRICT', false), FILTER_VALIDATE_BOOLEAN),
        
        // Circuit breaker configuration
        'circuit_breaker' => [
            'max_failures'   => max(1, (int) env('IMG_SCAN_CIRCUIT_MAX_FAILS', 5)),
            'cache_key'      => env('IMG_SCAN_CIRCUIT_CACHE_KEY', 'image_scan:circuit_failures'),
            'decay_seconds'  => (int) env('IMG_SCAN_CIRCUIT_TTL', 900),
        ],
        
        // Allowed base directory for scanning (prevents path traversal)
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
                '/etc', '/var', '/usr', '/root', '/home', '/opt', // Sensitive directories
                '/proc', '/sys', '/dev', '/run', // Virtual directories
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
        
        // Base directory for scanning rules (YARA)
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
        
        // Allowlist of permitted executable binaries for scanning
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
        
        // Maximum file size for individual scanning (bytes)
        'max_file_size_bytes' => env('IMG_SCAN_MAX_FILE_SIZE', 20 * 1024 * 1024),
        
        // Idle timeout for scanning (seconds)
        'idle_timeout' => env('IMG_SCAN_IDLE_TIMEOUT', 10),
        
        // Maximum size for scanning rules (bytes)
        'rules_max_bytes' => env('IMG_SCAN_RULES_MAX_BYTES', 2 * 1024 * 1024),
        
        // ClamAV specific configuration
        'clamav' => [
            'binary'   => env('IMG_SCAN_CLAMAV_BIN', '/usr/bin/clamdscan'),
            'timeout'  => env('IMG_SCAN_CLAMAV_TIMEOUT', 10),
            'arguments'=> env('IMG_SCAN_CLAMAV_ARGS', '--no-summary --fdpass'),
        ],
        
        // YARA specific configuration
        'yara' => [
            'binary'    => env('IMG_SCAN_YARA_BIN', '/usr/bin/yara'),
            'rules_path'=> env('IMG_SCAN_YARA_RULES', base_path('security/yara/images.yar')),
            'timeout'   => env('IMG_SCAN_YARA_TIMEOUT', 5),
            'arguments' => env('IMG_SCAN_YARA_ARGS', '--fail-on-warnings --nothreads'),
        ],
    ],
    
    // Suspicious payload patterns within image binaries (regex)
    'suspicious_payload_patterns' => [
        '/<\?php/i', // PHP code
        '/<\?=/i',  // Short PHP code
        '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s*\(/i', // Dangerous functions
        '/base64_decode\s*\(/i', // Base64 decoding
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
            // Maximum memory in MB for Imagick
            'memory_mb' => env('IMG_IMAGICK_MEMORY_MB', 128),
            // Maximum virtual memory in MB for Imagick
            'map_mb'    => env('IMG_IMAGICK_MAP_MB', 256),
            // Maximum execution time in ms for Imagick operations
            'time_ms'   => env('IMG_IMAGICK_TIME_MS', 750),
            // Maximum worker threads per Imagick operation
            'threads'   => env('IMG_IMAGICK_THREADS', 2),
        ],
        // Maximum memory in MB for GD operations (0 = unlimited)
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
        'max_attempts' => env('IMG_RATE_MAX', 10),
        'decay_seconds' => env('IMG_RATE_DECAY', 60),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Controls whether image conversions are processed synchronously or queued.
    |
    */
    
    // Default preference for queueing conversions (true = queued, false = sync)
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
    
    // Predefined sizes for avatar collection
    'avatar_sizes' => [
        'thumb'  => 128,
        'medium' => 256,
        'large'  => 512,
    ],
    
    // Specific preference for avatar collection (null = use queue_conversions_default)
    'avatar_queue_conversions' => env('IMG_AVATAR_QUEUE_CONVERSIONS', null),
    
    // Disk for avatar collection storage
    'avatar_disk' => env('AVATAR_DISK', env('FILESYSTEM_DISK', 'local')),
    
    // Avatar collection name
    'avatar_collection' => env('AVATAR_COLLECTION', 'avatar'),

    /*
    |--------------------------------------------------------------------------
    | Gallery Collection (Optional)
    |--------------------------------------------------------------------------
    |
    | Default collection/disk and sizes for gallery/portfolio images.
    | You can adjust these values per environment or override sizes per model.
    |
    */
    
    // Disk for gallery collection storage
    'gallery_disk' => env('GALLERY_DISK', env('FILESYSTEM_DISK', 'local')),
    
    // Gallery collection name
    'gallery_collection' => env('GALLERY_COLLECTION', 'gallery'),
    
    // Predefined sizes for gallery collection
    'gallery_sizes' => [
        'thumb'  => 320,
        'medium' => 1280,
        'large'  => 2048,
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-processing Collections
    |--------------------------------------------------------------------------
    |
    | Comma/space-separated list or array of collections for the listener that
    | queues optimization after conversions (default: avatar,gallery).
    |
    */
    
    // Collections list for post-processing optimization
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
    
    // Log channel or stack (use null for default)
    'log_channel' => env('IMG_LOG_CHANNEL', null),
    
    // Detail level for internal debug logs
    'debug' => env('IMG_DEBUG', false),
];
