<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains audit-related configuration options for user activity
    | logging and monitoring.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sample Rate
    |--------------------------------------------------------------------------
    |
    | Percentage of GET requests to audit (0.01 = 1%, 1.0 = 100%)
    | Lower values reduce log volume in high-traffic applications
    |
    */

    'sample_rate' => env('AUDIT_SAMPLE_RATE', 0.01),

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep audit logs (in days)
    |
    */

    'retention_days' => env('AUDIT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Which log channels to use for different types of audit logs
    |
    */

    'channels' => [
        'default' => env('AUDIT_LOG_CHANNEL', 'daily'),
        'security' => env('AUDIT_SECURITY_CHANNEL', 'security'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Critical Actions
    |--------------------------------------------------------------------------
    |
    | Routes that should always be audited regardless of sample rate
    | Use wildcard patterns: POST:users/*, DELETE:*, etc.
    |
    */

    'critical_actions' => [
        // Autenticación
        'POST:login',
        'POST:register',
        'POST:logout',
        'POST:password/*',
        'POST:forgot-password',
        'POST:reset-password',
        
        // Gestión de usuarios
        'POST:users/*',
        'PUT:users/*',
        'PATCH:users/*',
        'DELETE:users/*',
        
        // Configuración del sistema
        'POST:settings/*',
        'PUT:settings/*',
        'DELETE:settings/*',
        
        // Operaciones financieras
        'POST:payments/*',
        'POST:invoices/*',
        'POST:transactions/*',
        
        // Cambios de permisos
        'POST:roles/*',
        'POST:permissions/*',
        'PUT:roles/*',
        'PUT:permissions/*',
        
        // Acceso a datos sensibles
        'GET:admin/*',
        'GET:reports/*',
        'GET:analytics/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Routes
    |--------------------------------------------------------------------------
    |
    | Routes that should never be audited (performance optimization)
    |
    */

    'excluded_routes' => [
        // Assets estáticos
        'GET:css/*',
        'GET:js/*',
        'GET:images/*',
        'GET:fonts/*',
        
        // Health checks
        'GET:up',
        'GET:health',
        
        // Webhooks (si no necesitas auditar entradas externas)
        'POST:webhooks/*',
        
        // Endpoints de monitoreo
        'GET:metrics/*',
        'GET:status/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Fields
    |--------------------------------------------------------------------------
    |
    | Fields that should be redacted in audit logs
    |
    */

    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        '_token',
        'api_key',
        'secret',
        'private_key',
        'credit_card',
        'cvv',
        'ssn',
        'dni',
        'passport',
        'license',
        'social_security',
        'tax_id',
        'bank_account',
        'routing_number',
        'swift_code',
        'iban',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Settings to optimize audit performance
    |
    */

    'performance' => [
        // Tamaño máximo de request data a loggear
        'max_request_data_size' => 500,
        
        // Número máximo de elementos en arrays
        'max_array_items' => 10,
        
        // Longitud máxima de strings
        'max_string_length' => 500,
        
        // Cache de patrones de rutas
        'cache_route_patterns' => true,
        
        // TTL del cache (en segundos)
        'cache_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds for security alerts
    |
    */

    'alerts' => [
        // Número de intentos fallidos de login por IP
        'max_failed_logins_per_ip' => 10,
        
        // Número de acciones críticas por usuario por hora
        'max_critical_actions_per_user_per_hour' => 50,
        
        // Número de cambios de contraseña por usuario por día
        'max_password_changes_per_user_per_day' => 5,
        
        // Número de sesiones simultáneas por usuario
        'max_concurrent_sessions_per_user' => 5,
    ],

];