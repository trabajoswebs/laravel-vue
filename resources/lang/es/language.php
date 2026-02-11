<?php

return [
    // Mensajes de éxito
    'changed_successfully' => 'Idioma cambiado correctamente.',
    'detected_successfully' => 'Idioma detectado.',
    'cache_cleared' => 'Caché de traducciones limpiada.',
    'cache_prewarmed' => 'Traducciones precargadas.',
    'current_language_retrieved_successfully' => 'Idioma actual.',

    // Mensajes de error
    'unsupported_language' => 'El idioma :locale no está disponible.',
    'change_error' => 'No se pudo cambiar el idioma.',
    'detection_error' => 'No se pudo detectar el idioma.',
    'cache_clear_error' => 'Error al limpiar la caché.',
    'cache_prewarm_error' => 'Error al precargar las traducciones.',
    'cache_clear_only_dev' => 'Solo puedes limpiar la caché de traducciones en entornos de desarrollo o testing.',
    'cache_clear_permission_denied' => 'No tienes permisos para limpiar la caché de traducciones.',

    // Mensajes informativos
    'fallback_used' => 'Usando idioma por defecto: :locale.',
    'cache_cleared_count' => 'Caché limpiada para :count idiomas.',
    'cache_prewarmed_count' => 'Traducciones precargadas para :count idiomas.',

    // Fuentes de detección
    'detection_source_user' => 'Tu preferencia',
    'detection_source_session' => 'Tu sesión',
    'detection_source_cookie' => 'Tus cookies',
    'detection_source_browser' => 'Tu navegador',
    'detection_source_default' => 'Por defecto',

    // Campos de usuario
    'user_field_locale' => 'idioma',
    'user_field_language' => 'idioma',
    'user_field_preferred_language' => 'idioma preferido',

    // Estados
    'state_changing' => 'Cambiando idioma...',
    'state_changed' => 'Idioma actualizado.',
    'state_error' => 'Error al cambiar el idioma.',
    'state_unsupported' => 'Este idioma no está disponible.',
    'rate_limited' => 'Demasiados cambios de idioma. Espera :seconds segundos antes de intentarlo de nuevo.',
    'invalid_locale' => 'El idioma seleccionado no es válido.',

    // Nombres de idiomas
    'languages' => [
        'es' => 'Español',
        'en' => 'Inglés',
        'fr' => 'Francés',
        'de' => 'Alemán',
        'it' => 'Italiano',
        'pt' => 'Portugués',
        'ca' => 'Catalán',
        'eu' => 'Euskera',
        'gl' => 'Gallego',
    ],

    // Metadatos
    'metadata' => [
        'es' => [
            'name' => 'Español',
            'native_name' => 'Español',
            'flag' => '🇪🇸',
            'direction' => 'ltr',
        ],
        'en' => [
            'name' => 'Inglés',
            'native_name' => 'English',
            'flag' => '🇬🇧',
            'direction' => 'ltr',
        ],
        'fr' => [
            'name' => 'Francés',
            'native_name' => 'Français',
            'flag' => '🇫🇷',
            'direction' => 'ltr',
        ],
        'de' => [
            'name' => 'Alemán',
            'native_name' => 'Deutsch',
            'flag' => '🇩🇪',
            'direction' => 'ltr',
        ],
        'ca' => [
            'name' => 'Catalán',
            'native_name' => 'Català',
            'flag' => '🏴',
            'direction' => 'ltr',
        ],
        'eu' => [
            'name' => 'Euskera',
            'native_name' => 'Euskara',
            'flag' => '🏴',
            'direction' => 'ltr',
        ],
        'gl' => [
            'name' => 'Gallego',
            'native_name' => 'Galego',
            'flag' => '🏴',
            'direction' => 'ltr',
        ],
    ],

    // Errores específicos
    'errors' => [
        'invalid_locale_format' => 'Formato de idioma incorrecto',
        'cache_not_supported' => 'Sistema de caché no compatible',
        'file_not_found' => 'Archivo de traducción no encontrado',
        'file_corrupted' => 'Archivo de traducción dañado',
        'json_invalid' => 'Formato de traducciones incorrecto',
        'permission_denied' => 'Permiso denegado',
        'network_error' => 'Error de conexión',
        'server_error' => 'Error del servidor',
        'unknown_error' => 'Error desconocido',
    ],

    // Logs y debugging
    'logs' => [
        'detection_started' => 'Detectando idioma...',
        'user_preference_found' => 'Preferencia de usuario: :locale',
        'session_preference_found' => 'Preferencia de sesión: :locale',
        'cookie_preference_found' => 'Preferencia de cookie: :locale',
        'browser_preference_found' => 'Preferencia del navegador: :locale',
        'fallback_used' => 'Idioma por defecto: :locale',
        'cache_hit' => 'Caché encontrada: :locale',
        'cache_miss' => 'Caché no encontrada: :locale',
        'cache_cleared' => 'Caché limpiada: :locale',
        'translation_loaded' => 'Traducciones cargadas: :locale',
        'translation_error' => 'Error cargando traducciones: :locale',
    ],    
];
