<?php

return [
    // Idiomas soportados (ISO 639-1 o con región: es, en, es-ES, en-US)
    'supported' => ['es', 'en'],

    // Fallback si no se detecta un idioma válido
    'fallback' => env('APP_FALLBACK_LOCALE', 'es'),

    // Cache TTL en horas
    'cache_hours' => env('TRANSLATION_CACHE_HOURS', 6),

    // Archivos PHP que cargamos como namespaces
    'translation_files' => [
        'validation', 'auth', 'passwords', 'verification', 'pagination',
        'errors', 'success', 'messages', 'help', 'confirmations', 'notifications',
        'language',
    ],

    // Si permitimos HTML en las traducciones (por defecto NO)
    'allow_html' => env('LOCALES_ALLOW_HTML', false),

    // Si allow_html = true, estas etiquetas se permiten (strip_tags)
    'allowed_html_tags' => '<b><i><strong><em><ul><ol><li><p><br><span><a>',

    // Validación JSON: límites simples para evitar payloads maliciosos
    'json' => [
        'max_depth' => 8,
        'max_key_length' => 250,
        'max_total_keys' => 2000,
    ],

    // Metadata por idioma
    'metadata' => [
        'es' => [
            'name' => 'Español',
            'native_name' => 'Español',
            'flag' => '🇪🇸',
            'direction' => 'ltr',
        ],
        'en' => [
            'name' => 'English',
            'native_name' => 'English',
            'flag' => '🇬🇧',
            'direction' => 'ltr',
        ],
    ],
];
