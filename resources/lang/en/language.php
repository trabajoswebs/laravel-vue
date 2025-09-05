<?php

return [
    // Success messages
    'changed_successfully' => 'Language changed successfully',
    'detected_successfully' => 'Language detected successfully',
    'cache_cleared' => 'Translation cache cleared successfully',
    'cache_prewarmed' => 'Translation cache prewarmed successfully',
    'current_language_retrieved_successfully' => 'Current language has been retrieved',

    // Error messages
    'unsupported_language' => 'Unsupported language: :locale',
    'change_error' => 'Error changing language',
    'detection_error' => 'Error detecting language',
    'cache_clear_error' => 'Error clearing translation cache',
    'cache_prewarm_error' => 'Error prewarming translation cache',

    // Informative messages
    'fallback_used' => 'Using fallback language: :locale',
    'cache_cleared_count' => 'Cache cleared for :count languages',
    'cache_prewarmed_count' => 'Cache prewarmed for :count languages',

    // Detection sources
    'detection_source_user' => 'User preference',
    'detection_source_session' => 'Session',
    'detection_source_cookie' => 'Cookie',
    'detection_source_browser' => 'Browser',
    'detection_source_default' => 'Default',

    // User fields
    'user_field_locale' => 'locale',
    'user_field_language' => 'language',
    'user_field_preferred_language' => 'preferred_language',

    // States
    'state_changing' => 'Changing language...',
    'state_changed' => 'Language changed',
    'state_error' => 'Change error',
    'state_unsupported' => 'Unsupported language',

    // Language names
    'languages' => [
        'es' => 'Spanish',
        'en' => 'English',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
    ],

    // Metadata
    'metadata' => [
        'es' => [
            'name' => 'Spanish',
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
            'name' => 'French',
            'native_name' => 'Français',
            'flag' => '🇫🇷',
            'direction' => 'ltr',
        ],
        'de' => [
            'name' => 'German',
            'native_name' => 'Deutsch',
            'flag' => '🇩🇪',
            'direction' => 'ltr',
        ],
    ],

    // Specific errors
    'errors' => [
        'invalid_locale_format' => 'Invalid locale format',
        'cache_not_supported' => 'Cache system not supported',
        'file_not_found' => 'Translation file not found',
        'file_corrupted' => 'Translation file corrupted',
        'json_invalid' => 'Invalid translation JSON',
        'permission_denied' => 'Permission denied',
        'network_error' => 'Network error',
        'server_error' => 'Server error',
        'unknown_error' => 'Unknown error',
    ],

    // Logs and debugging
    'logs' => [
        'detection_started' => 'Starting language detection',
        'user_preference_found' => 'User preference found: :locale',
        'session_preference_found' => 'Session preference found: :locale',
        'cookie_preference_found' => 'Cookie preference found: :locale',
        'browser_preference_found' => 'Browser preference found: :locale',
        'fallback_used' => 'Using fallback language: :locale',
        'cache_hit' => 'Cache hit for: :locale',
        'cache_miss' => 'Cache miss for: :locale',
        'cache_cleared' => 'Cache cleared for: :locale',
        'translation_loaded' => 'Translations loaded for: :locale',
        'translation_error' => 'Error loading translations for: :locale',
    ],
];
