<?php

declare(strict_types=1);

return [
    'private_disk' => env('UPLOAD_PRIVATE_DISK', env('FILESYSTEM_DISK', 'public')),

    'owner_id' => [
        // int|uuid|ulid
        'mode' => env('UPLOAD_OWNER_ID_MODE', 'int'),
        'min_int' => (int) env('UPLOAD_OWNER_ID_MIN_INT', 1),
    ],

    'import_csv' => [
        'sample_bytes' => (int) env('UPLOAD_IMPORT_CSV_SAMPLE_BYTES', 65536),
        'sniff_lines' => (int) env('UPLOAD_IMPORT_CSV_SNIFF_LINES', 20),
        'max_columns' => (int) env('UPLOAD_IMPORT_CSV_MAX_COLUMNS', 50),
        'max_line_length' => (int) env('UPLOAD_IMPORT_CSV_MAX_LINE_LENGTH', 8192),
        'min_consistency_ratio' => (float) env('UPLOAD_IMPORT_CSV_MIN_CONSISTENCY_RATIO', 0.6),
    ],

    'virus_scanning' => [
        // Permite desactivar el escaneo AV en entornos locales/testing.
        'enabled' => filter_var(env('UPLOAD_SCAN_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
