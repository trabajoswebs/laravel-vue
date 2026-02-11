<?php

return [
    // Success messages
    'changed_successfully' => 'Language updated.',
    'detected_successfully' => 'Language detected without any trouble.',
    'cache_cleared' => 'Translation cache cleared.',
    'cache_prewarmed' => 'Translation cache preloaded and ready.',
    'current_language_retrieved_successfully' => 'Current language retrieved.',

    // Error messages
    'unsupported_language' => 'We don’t support the :locale language yet.',
    'change_error' => 'We couldn’t change the language.',
    'detection_error' => 'We couldn’t detect the language.',
    'cache_clear_error' => 'We couldn’t clear the translation cache.',
    'cache_prewarm_error' => 'We couldn’t preload the translation cache.',
    'cache_clear_only_dev' => 'Translation cache can only be cleared in development or testing environments.',
    'cache_clear_permission_denied' => 'You do not have permission to clear the translation cache.',

    // Informative messages
    'fallback_used' => 'Falling back to :locale',
    'cache_cleared_count' => 'Cache cleared for :count languages.',
    'cache_prewarmed_count' => 'Cache preloaded for :count languages.',

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
    'state_changing' => 'We’re changing the language...',
    'state_changed' => 'Language updated.',
    'state_error' => 'Couldn’t change the language.',
    'state_unsupported' => 'Language not available.',
    'rate_limited' => 'Too many language changes. Please wait :seconds seconds before trying again.',
    'invalid_locale' => 'The selected language is not valid.',

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
        'fallback_used' => 'Falling back to :locale',
        'cache_hit' => 'Cache hit for: :locale',
        'cache_miss' => 'Cache miss for: :locale',
        'cache_cleared' => 'Cache cleared for: :locale',
        'translation_loaded' => 'Translations loaded for: :locale',
        'translation_error' => 'Error loading translations for: :locale',
    ],
];
