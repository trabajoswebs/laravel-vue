<?php

$parseCspSources = static function ($value): array {
    if (empty($value)) {
        return [];
    }

    $sources = preg_split('/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

    if (!is_array($sources)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $sources)));
};

$productionImgHosts = array_filter(array_merge(["'self'", 'data:'], $parseCspSources(env('CSP_IMG_HOSTS'))));
$productionConnectHosts = array_filter(array_merge(["'self'", env('APP_URL')], $parseCspSources(env('CSP_CONNECT_HOSTS'))));

$productionReportUri = env('CSP_REPORT_URI');
$productionReportTo = env('CSP_REPORT_TO');
$reportToEndpoints = array_filter($parseCspSources(env('CSP_REPORT_TO_ENDPOINTS')));
$reportToMaxAge = (int) env('CSP_REPORT_TO_MAX_AGE', 10886400);
$reportToIncludeSubdomains = filter_var(env('CSP_REPORT_TO_INCLUDE_SUBDOMAINS', false), FILTER_VALIDATE_BOOLEAN);

$developmentImgHosts = array_filter(array_merge(["'self'", 'data:', 'https:', 'blob:'], $parseCspSources(env('CSP_DEV_IMG_HOSTS'))));
$developmentConnectHosts = array_filter(array_merge([
    "'self'",
    env('APP_FRONTEND_URL', 'http://localhost:3000'),
    env('APP_URL'),
    'ws:',
    'wss:',
], $parseCspSources(env('CSP_DEV_CONNECT_HOSTS'))));

return [

    'debug_csp' => env('SECURITY_DEBUG_CSP', false),

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
        'log_authentication_events' => env('LOG_AUTHENTICATION_EVENTS', true),
        'log_failed_logins' => env('LOG_FAILED_LOGINS', true),
        'log_successful_logins' => env('LOG_SUCCESSFUL_LOGINS', true),
        'log_password_changes' => env('LOG_PASSWORD_CHANGES', true),
        'log_logouts' => env('LOG_LOGOUTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Activity Logging
    |--------------------------------------------------------------------------
    */

    'user_activity' => [
        'log_user_actions' => env('LOG_USER_ACTIONS', true),
        'log_ip_addresses' => env('LOG_IP_ADDRESSES', true),
        'log_user_agents' => env('LOG_USER_AGENTS', true),
        'log_sensitive_changes' => env('LOG_SENSITIVE_CHANGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limiting' => [
        'login_max_attempts' => env('LOGIN_MAX_ATTEMPTS', 5),
        'login_decay_minutes' => env('LOGIN_DECAY_MINUTES', 15),
        'register_max_attempts' => env('REGISTER_MAX_ATTEMPTS', 3),
        'register_decay_minutes' => env('REGISTER_DECAY_MINUTES', 10),
        'password_reset_max_attempts' => env('PASSWORD_RESET_MAX_ATTEMPTS', 3),
        'password_reset_decay_minutes' => env('PASSWORD_RESET_DECAY_MINUTES', 10),
        'api_requests_per_minute' => env('API_RATE_LIMIT_PER_MINUTE', 60),
        'general_requests_per_minute' => env('GENERAL_REQUESTS_PER_MINUTE', 100),
        'language_change_max_attempts' => env('LANGUAGE_CHANGE_MAX_ATTEMPTS', 5),
        'language_change_decay_minutes' => env('LANGUAGE_CHANGE_DECAY_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Security
    |--------------------------------------------------------------------------
    */

    'session' => [
        'regenerate_on_login' => env('SESSION_REGENERATE_ON_LOGIN', true),
        'invalidate_on_logout' => env('SESSION_INVALIDATE_ON_LOGOUT', true),
        'regenerate_csrf_on_logout' => env('SESSION_REGENERATE_CSRF_ON_LOGOUT', true),
        'log_sessions' => env('LOG_SESSIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Security
    |--------------------------------------------------------------------------
    */

    'file_uploads' => [
        'allowed_types' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'document' => ['pdf', 'doc', 'docx', 'txt'],
            'archive' => ['zip', 'rar', '7z'],
        ],
        'max_size' => env('MAX_FILE_SIZE', 10),
        'scan_for_malware' => env('SCAN_FILES_FOR_MALWARE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Security
    |--------------------------------------------------------------------------
    */

    'api' => [
        'require_https' => env('API_REQUIRE_HTTPS', true),
        'rate_limit_per_minute' => env('API_RATE_LIMIT_PER_MINUTE', 60),
        'log_api_requests' => env('LOG_API_REQUESTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    */

    'csp' => [
        'report_to_endpoints' => $reportToEndpoints,
        'report_to_max_age' => $reportToMaxAge,
        'report_to_include_subdomains' => $reportToIncludeSubdomains,
        'report_uri' => $productionReportUri,
        'report_to' => $productionReportTo,
        'development' => [
            'default-src' => array_filter(["'self'", env('APP_FRONTEND_URL', 'http://localhost:3000')]),
            'script-src' => array_filter(["'self'", "'unsafe-inline'", "'unsafe-eval'", env('APP_FRONTEND_URL', 'http://localhost:3000')]),
            'style-src' => array_filter(["'self'", "'unsafe-inline'", env('APP_FRONTEND_URL', 'http://localhost:3000'), 'https://fonts.googleapis.com', 'https://fonts.bunny.net']),
            'img-src' => $developmentImgHosts,
            'font-src' => array_filter(["'self'", 'https://fonts.gstatic.com', 'https://fonts.bunny.net', env('APP_FRONTEND_URL', 'http://localhost:3000')]),
            'connect-src' => $developmentConnectHosts,
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ],

        'production' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{nonce}'"],
            'style-src' => ["'self'", "'nonce-{nonce}'", 'https://fonts.googleapis.com', 'https://fonts.bunny.net'],
            'img-src' => $productionImgHosts,
            'font-src' => ["'self'", 'https://fonts.gstatic.com', 'https://fonts.bunny.net'],
            'connect-src' => $productionConnectHosts,
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'report-uri' => array_filter([$productionReportUri]),
            'report-to' => array_filter([$productionReportTo]),
            'upgrade-insecure-requests' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    */

    'security_headers' => [
        'enable_hsts' => env('SECURITY_ENABLE_HSTS', true),
        'enable_frame_options' => env('SECURITY_ENABLE_FRAME_OPTIONS', true),
        'enable_content_type_options' => env('SECURITY_ENABLE_CONTENT_TYPE_OPTIONS', true),
        'enable_xss_protection' => env('SECURITY_ENABLE_XSS_PROTECTION', true),
        'enable_referrer_policy' => env('SECURITY_ENABLE_REFERRER_POLICY', true),
        'enable_permissions_policy' => env('SECURITY_ENABLE_PERMISSIONS_POLICY', true),
        'enable_corp' => env('SECURITY_ENABLE_CORP', true),
        'enable_coop' => env('SECURITY_ENABLE_COOP', true),
        'enable_coep' => env('SECURITY_ENABLE_COEP', false),
        'hsts_max_age' => env('SECURITY_HSTS_MAX_AGE', 31536000),
        'corp_policy' => env('SECURITY_CORP_POLICY', 'same-origin'),
        'coop_policy' => env('SECURITY_COOP_POLICY', 'same-origin'),
        'coep_policy' => env('SECURITY_COEP_POLICY', 'require-corp'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => $parseCspSources(env('SECURITY_PERMISSIONS_POLICY')),
    ],

    'trusted_proxies' => [
        'proxies' => env('TRUSTED_PROXIES'),
        'headers' => env('TRUSTED_PROXIES_HEADERS', 'all'),
    ],

];
