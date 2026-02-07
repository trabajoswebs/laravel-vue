<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,           // Permite servir archivos directamente
            'throw' => false,          // No lanza excepciones si falla
            'report' => false,         // No reporta errores al logger
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',  // URL base para archivos públicos
            'visibility' => 'public',            // Archivos accesibles públicamente
            'throw' => false,
            'report' => false,
        ],

        'avatars' => [
            'driver' => 'local',
            'root' => storage_path('app/private/avatars'),
            'visibility' => 'private',           // Archivos privados, acceso controlado
            'permissions' => [                   // Permisos específicos para archivos privados
                'file' => [
                    'private' => 0600,          // Lectura/escritura solo para owner
                ],
                'dir' => [
                    'private' => 0700,          // Lectura/escritura/ejecución solo para owner
                ],
            ],
            'throw' => false,
            'report' => false,
        ],

        'media_private' => [
            'driver' => 'local',
            'root' => storage_path('app/private/media'),
            'visibility' => 'private',           // Archivos multimedia privados
            'permissions' => [                   // Permisos restringidos para media
                'file' => [
                    'private' => 0600,
                ],
                'dir' => [
                    'private' => 0700,
                ],
            ],
            'serve' => false,                    // No se sirven directamente por Laravel
            'throw' => false,
            'report' => false,
        ],

        'gallery' => [
            'driver' => 'local',
            'root' => storage_path('app/private/gallery'),
            'visibility' => 'private',           // Galería de imágenes privadas
            'permissions' => [                   // Permisos restringidos para galería
                'file' => [
                    'private' => 0600,
                ],
                'dir' => [
                    'private' => 0700,
                ],
            ],
            'throw' => false,
            'report' => false,
        ],

        'quarantine' => [                        // Disco para archivos en cuarentena
            'driver' => 'local',
            'root' => storage_path('app/private/quarantine'),
            'visibility' => 'private',           // Acceso altamente restringido
            'permissions' => [                   // Máxima restricción para seguridad
                'file' => [
                    'private' => 0600,
                ],
                'dir' => [
                    'private' => 0700,
                ],
            ],
            'throw' => false,
            'report' => false,
        ],

        's3' => [                               // Disco principal en AWS S3
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),  // Clave de acceso AWS
            'secret' => env('AWS_SECRET_ACCESS_KEY'), // Secreto de acceso AWS
            'region' => env('AWS_DEFAULT_REGION'),    // Región AWS
            'bucket' => env('AWS_BUCKET'),            // Bucket S3
            'url' => env('AWS_URL'),                  // URL personalizada (opcional)
            'endpoint' => env('AWS_ENDPOINT'),        // Endpoint personalizado (opcional)
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false), // Formato de URL
            'visibility' => 'private',                // Archivos privados por defecto
            'throw' => false,
            'report' => false,
        ],

        's3_private' => [                       // Disco alternativo en AWS S3 para privados
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PRIVATE_BUCKET', env('AWS_BUCKET')), // Bucket privado o fallback al principal
            'url' => env('AWS_PRIVATE_URL', env('AWS_URL')),         // URL privada o fallback
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',           // Asegura visibilidad privada
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'), // Enlace simbólico para acceder a archivos públicos
    ],

];