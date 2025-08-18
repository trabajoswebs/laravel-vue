<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains security-related configuration options for your
    | Laravel application. These settings help protect your application
    | from various security threats.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Authentication Security
    |--------------------------------------------------------------------------
    |
    | Configure authentication-related security settings.
    |
    */

    'authentication' => [
        // Log de eventos de autenticación
        'log_authentication_events' => env('LOG_AUTHENTICATION_EVENTS', true),
        
        // Log de intentos fallidos de login
        'log_failed_logins' => env('LOG_FAILED_LOGINS', true),
        
        // Log de logins exitosos
        'log_successful_logins' => env('LOG_SUCCESSFUL_LOGINS', true),
        
        // Log de cambios de contraseña
        'log_password_changes' => env('LOG_PASSWORD_CHANGES', true),
        
        // Log de logout
        'log_logouts' => env('LOG_LOGOUTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Activity Logging
    |--------------------------------------------------------------------------
    |
    | Configure user activity logging settings.
    |
    */

    'user_activity' => [
        // Log de acciones del usuario
        'log_user_actions' => env('LOG_USER_ACTIONS', true),
        
        // Log de direcciones IP
        'log_ip_addresses' => env('LOG_IP_ADDRESSES', true),
        
        // Log de user agents
        'log_user_agents' => env('LOG_USER_AGENTS', true),
        
        // Log de cambios de datos sensibles
        'log_sensitive_changes' => env('LOG_SENSITIVE_CHANGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting settings for various endpoints.
    |
    */

    'rate_limiting' => [
        // Intentos de login por minuto
        'login_attempts_per_minute' => env('LOGIN_ATTEMPTS_PER_MINUTE', 5),
        
        // Intentos de registro por minuto
        'register_attempts_per_minute' => env('REGISTER_ATTEMPTS_PER_MINUTE', 3),
        
        // Intentos de reset de contraseña por minuto
        'password_reset_attempts_per_minute' => env('PASSWORD_RESET_ATTEMPTS_PER_MINUTE', 3),
        
        // Requests generales por minuto
        'general_requests_per_minute' => env('GENERAL_REQUESTS_PER_MINUTE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    |
    | Configure session-related security settings.
    |
    */

    'session' => [
        // Regenerar ID de sesión en login
        'regenerate_on_login' => env('SESSION_REGENERATE_ON_LOGIN', true),
        
        // Invalidar sesión en logout
        'invalidate_on_logout' => env('SESSION_INVALIDATE_ON_LOGOUT', true),
        
        // Regenerar token CSRF en logout
        'regenerate_csrf_on_logout' => env('SESSION_REGENERATE_CSRF_ON_LOGOUT', true),
        
        // Log de sesiones
        'log_sessions' => env('LOG_SESSIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Security
    |--------------------------------------------------------------------------
    |
    | Configure file upload security settings.
    |
    */

    'file_uploads' => [
        // Tipos de archivo permitidos
        'allowed_types' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'document' => ['pdf', 'doc', 'docx', 'txt'],
            'archive' => ['zip', 'rar', '7z'],
        ],
        
        // Tamaño máximo de archivo (en MB)
        'max_size' => env('MAX_FILE_SIZE', 10),
        
        // Escanear archivos por malware
        'scan_for_malware' => env('SCAN_FILES_FOR_MALWARE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Security
    |--------------------------------------------------------------------------
    |
    | Configure API-related security settings.
    |
    */

    'api' => [
        // Requerir HTTPS para APIs
        'require_https' => env('API_REQUIRE_HTTPS', true),
        
        // Rate limiting para APIs
        'rate_limit_per_minute' => env('API_RATE_LIMIT_PER_MINUTE', 60),
        
        // Log de requests a APIs
        'log_api_requests' => env('LOG_API_REQUESTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Configure CSP directives for enhanced security.
    |
    */

    'csp' => [
        // Directivas base para desarrollo
        'development' => [
            'default-src' => ["'self'", env('APP_FRONTEND_URL', 'http://localhost:3000')],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", env('APP_FRONTEND_URL', 'http://localhost:3000')],
            'style-src' => ["'self'", "'unsafe-inline'", env('APP_FRONTEND_URL', 'http://localhost:3000'), 'https://fonts.googleapis.com'],
            'img-src' => ["'self'", 'data:', 'https:', env('APP_FRONTEND_URL', 'http://localhost:3000')],
            'font-src' => ["'self'", 'https://fonts.gstatic.com', env('APP_FRONTEND_URL', 'http://localhost:3000')],
            'connect-src' => ["'self'", env('APP_FRONTEND_URL', 'http://localhost:3000'), env('APP_URL'), 'ws:', 'wss:'],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ],

        // Directivas estrictas para producción
        'production' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{nonce}'"],
            'style-src' => ["'self'", "'nonce-{nonce}'", 'https://fonts.googleapis.com'],
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'", 'https://fonts.gstatic.com'],
            'connect-src' => ["'self'", env('APP_URL')],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'upgrade-insecure-requests' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Configure which security headers to enable.
    |
    */

    'security_headers' => [
        'enable_hsts' => env('SECURITY_ENABLE_HSTS', true),
        'enable_frame_options' => env('SECURITY_ENABLE_FRAME_OPTIONS', true),
        'enable_content_type_options' => env('SECURITY_ENABLE_CONTENT_TYPE_OPTIONS', true),
        'enable_xss_protection' => env('SECURITY_ENABLE_XSS_PROTECTION', true),
        'enable_referrer_policy' => env('SECURITY_ENABLE_REFERRER_POLICY', true),
        'enable_permissions_policy' => env('SECURITY_ENABLE_PERMISSIONS_POLICY', true),
    ],

];
