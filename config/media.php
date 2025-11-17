<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media cleanup scheduler defaults
    |--------------------------------------------------------------------------
    |
    | These settings control how long pending cleanup states are kept before
    | the scheduler forces a degraded cleanup. Adjust via environment
    | variables per environment when needed.
    |
    */
    
    // Hours before a media cleanup state is considered stale and purged.
    'cleanup' => [
        'state_ttl_hours' => env('MEDIA_CLEANUP_TTL_HOURS', 48),
    ],

    'signed_serve' => [
        'enabled' => (bool) env('MEDIA_SIGNED_SERVE_ENABLED', false),
    ],

    'quarantine' => [
        'disk' => env('MEDIA_QUARANTINE_DISK', 'quarantine'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Disks
    |--------------------------------------------------------------------------
    |
    | Define qué discos de Filesystem son válidos para que el listener procese
    | conversiones. Cuando está vacío, usa todos los disks configurados.
    |
    */
    'allowed_disks' => env('MEDIA_ALLOWED_DISKS') !== null
        ? array_map('trim', explode(',', (string) env('MEDIA_ALLOWED_DISKS', '')))
        : [],
];
