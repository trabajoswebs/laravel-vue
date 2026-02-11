<?php

declare(strict_types=1);

return [
    'private_disk' => env('UPLOAD_PRIVATE_DISK', env('FILESYSTEM_DISK', 'public')),

    'virus_scanning' => [
        // Permite desactivar el escaneo AV en entornos locales/testing.
        'enabled' => filter_var(env('UPLOAD_SCAN_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
