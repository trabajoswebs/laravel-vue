<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Conexión por defecto del sistema de colas.
    | Recomendación:
    | - LOCAL/CI: "database" (simple y suficiente).
    | - PRODUCCIÓN: "redis" (para concurrencia real + Horizon).
    |
    | .env -> QUEUE_CONNECTION=database   (local)
    | .env -> QUEUE_CONNECTION=redis      (producción)
    */
    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Name Aliases (custom helper)
    |--------------------------------------------------------------------------
    |
    | Atajos centralizados para nombres de colas. Así no hardcodeas "media",
    | "low", etc. en cada Job. Úsalos como:
    |   ->onQueue(config('queue.aliases.media'))
    |
    | No es parte del core, pero es 100% seguro y práctico.
    */
    'aliases' => [
        'default' => env('QUEUE_DEFAULT', 'default'), // Cola general
        'media'   => env('QUEUE_MEDIA', 'media'),     // Cola para procesado de medios (avatars, conversions, etc.)
        'low'     => env('QUEUE_LOW', 'low'),         // Cola de baja prioridad / mantenimiento
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Configuración de cada backend soportado por Laravel.
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    | Tips:
    | - "database": bueno para local, evita dependencias extra.
    | - "redis": recomendado en producción (Horizon).
    */
    'connections' => [

        // Ejecuta en el mismo proceso (sin cola). Útil en tests muy unitarios.
        'sync' => [
            'driver' => 'sync',
        ],

        // Cola en tabla SQL (válido para local/CI; en prod puede aguantar con poco tráfico).
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),         // Ej.: null (usa DB por defecto)
            'table' => env('DB_QUEUE_TABLE', 'jobs'),           // Tabla para jobs
            'queue' => env('DB_QUEUE', 'default'),              // Nombre de cola por defecto
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90), // Segundos para reintentar si el worker “lo retuvo”
            'after_commit' => false,                            // Recomendación: activar por Job con ->afterCommit()
        ],

        // Si usas Beanstalkd (poco común hoy día, pero soportado).
        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        // Amazon SQS (si migras a AWS más adelante).
        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        // Redis (recomendado para producción con Horizon).
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'), // Conexión redis del config/database.php
            'queue' => env('REDIS_QUEUE', 'default'),                 // Cola por defecto
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,                                      // Opcional: long-polling. Ej.: 5
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | Dónde se guardan los metadatos de "lotes" (batches).
    | Producción: usa tu DB real (mysql/pgsql).
    */
    'batching' => [
        'database' => env('BATCH_DB_CONNECTION', env('DB_CONNECTION', 'pgsql')), // Sug.: BATCH_DB_CONNECTION=mysql
        'table' => env('BATCH_DB_TABLE', 'job_batches'),                          // Tabla de batches
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | Dónde se anotan los jobs fallidos (histórico/forense).
    | "database-uuids" es la opción moderna por defecto.
    */
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => env('QUEUE_FAILED_TABLE', 'failed_jobs'),
    ],

];
