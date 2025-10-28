<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verificación de existencia en disco
    |--------------------------------------------------------------------------
    |
    | true  -> hace llamadas al filesystem para confirmar que el directorio existe.
    | false -> asume que existe y se limita a inferir rutas (más rápido y barato).
    |
    */
    'check_exists' => env('MEDIA_COLLECTOR_CHECK_EXISTS', true),

    /*
    |--------------------------------------------------------------------------
    | Nivel de log cuando falta un directorio esperado
    |--------------------------------------------------------------------------
    |
    | true  -> usa nivel "debug"
    | false -> usa nivel "info"
    |
    */
    'log_missing_as_debug' => env('MEDIA_COLLECTOR_LOG_MISSING_DEBUG', true),

    // Discos donde asumimos existencia (bypass de chequeo caro)
    'assume_exist_for_disks' => array_filter(
        array_map('trim', explode(',', env('MEDIA_COLLECTOR_ASSUME_EXIST_FOR_DISKS', '')))
    ),
];
