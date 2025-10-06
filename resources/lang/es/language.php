<?php

return [
    // Mensajes de éxito
    'changed_successfully' => 'Hemos cambiado el idioma.',
    'detected_successfully' => 'Idioma detectado sin problema.',
    'cache_cleared' => 'Hemos vaciado la caché de traducciones.',
    'cache_prewarmed' => 'Traducciones precargadas y listas.',
    'current_language_retrieved_successfully' => 'Idioma actual recuperado.',

    // Mensajes de error
    'unsupported_language' => 'No trabajamos con el idioma :locale.',
    'change_error' => 'No hemos podido cambiar el idioma.',
    'detection_error' => 'No hemos podido detectar el idioma.',
    'cache_clear_error' => 'No hemos podido vaciar la caché de traducciones.',
    'cache_prewarm_error' => 'No hemos podido precargar la caché de traducciones.',

    // Mensajes informativos
    'fallback_used' => 'Usamos el idioma por defecto: :locale.',
    'cache_cleared_count' => 'Caché vaciada para :count idiomas.',
    'cache_prewarmed_count' => 'Precargamos traducciones para :count idiomas.',

    // Fuentes de detección
    'detection_source_user' => 'Preferencia del usuario',
    'detection_source_session' => 'Sesión',
    'detection_source_cookie' => 'Cookie',
    'detection_source_browser' => 'Navegador',
    'detection_source_default' => 'Por defecto',

    // Campos de usuario
    'user_field_locale' => 'locale',
    'user_field_language' => 'language',
    'user_field_preferred_language' => 'preferred_language',

    // Estados
    'state_changing' => 'Estamos cambiando el idioma...',
    'state_changed' => 'Idioma actualizado.',
    'state_error' => 'No hemos podido cambiarlo.',
    'state_unsupported' => 'Idioma no disponible.',

    // Nombres de idiomas
    'languages' => [
        'es' => 'Español',
        'en' => 'English',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'it' => 'Italiano',
        'pt' => 'Português',
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
            'name' => 'English',
            'native_name' => 'English',
            'flag' => '🇺🇸',
            'direction' => 'ltr',
        ],
        'fr' => [
            'name' => 'Français',
            'native_name' => 'Français',
            'flag' => '🇫🇷',
            'direction' => 'ltr',
        ],
        'de' => [
            'name' => 'Deutsch',
            'native_name' => 'Deutsch',
            'flag' => '🇩🇪',
            'direction' => 'ltr',
        ],
    ],

    // Errores específicos
    'errors' => [
        'invalid_locale_format' => 'Formato de idioma inválido',
        'cache_not_supported' => 'Sistema de caché no soportado',
        'file_not_found' => 'Archivo de traducción no encontrado',
        'file_corrupted' => 'Archivo de traducción corrupto',
        'json_invalid' => 'JSON de traducciones inválido',
        'permission_denied' => 'Permiso denegado',
        'network_error' => 'Error de red',
        'server_error' => 'Error del servidor',
        'unknown_error' => 'Error desconocido',
    ],

    // Logs y debugging
    'logs' => [
        'detection_started' => 'Iniciando detección de idioma',
        'user_preference_found' => 'Preferencia de usuario encontrada: :locale',
        'session_preference_found' => 'Preferencia de sesión encontrada: :locale',
        'cookie_preference_found' => 'Preferencia de cookie encontrada: :locale',
        'browser_preference_found' => 'Preferencia de navegador encontrada: :locale',
        'fallback_used' => 'Usando idioma por defecto: :locale',
        'cache_hit' => 'Caché encontrada para: :locale',
        'cache_miss' => 'Caché no encontrada para: :locale',
        'cache_cleared' => 'Caché limpiada para: :locale',
        'translation_loaded' => 'Traducciones cargadas para: :locale',
        'translation_error' => 'Error cargando traducciones para: :locale',
    ],
];
