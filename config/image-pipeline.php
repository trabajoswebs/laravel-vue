<?php

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

    // Dimensión mínima (ancho y alto) requerida para aceptar la imagen
    'min_dimension' => env('IMG_MIN_DIMENSION', 128), // px

    // Límite de megapíxeles admitidos (protección DoS)
    'max_megapixels' => env('IMG_MAX_MEGAPIXELS', 48.0),

    // Lado mayor máximo del resultado (resize manteniendo proporción)
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

    // WebP: calidad 0–100 (70–80 recomendado)
    // Nota: la clave 'webp_quality' se define más abajo como valor único (evita duplicados).

    // Si la imagen tiene transparencia, ¿forzar WebP?
    'alpha_to_webp' => env('IMG_ALPHA_TO_WEBP', true),

    // Umbral a partir del cual activar JPEG progresivo
    'jpeg_progressive_min' => env('IMG_JPEG_PROGRESSIVE_MIN', 1200), // px (lado mayor)

    // Método de WebP (0–6). 6 = más calidad/tiempo
    'webp_method' => env('IMG_WEBP_METHOD', 6),

    // Patrón de firmas sospechosas dentro de binarios de imagen (regex). Ajustable por entorno.
    'suspicious_payload_patterns' => [
        '/<\?php/i',
        '/<\?=/i',
        '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s*\(/i',
        '/base64_decode\s*\(/i',
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
    'gallery_disk' => env('GALLERY_DISK', env('FILESYSTEM_DISK', 'local')),
    'gallery_collection' => env('GALLERY_COLLECTION', 'gallery'),
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
    'postprocess_collections' => env('IMG_POSTPROCESS_COLLECTIONS', 'avatar,gallery'),

];
