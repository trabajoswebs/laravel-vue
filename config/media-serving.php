<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed media path prefixes for /media endpoint
    |--------------------------------------------------------------------------
    |
    | Define the prefixes (with simple placeholders) that can be served via
    | the authenticated /media/{path} endpoint. This prevents the controller
    | from becoming a generic file server.
    |
    | Supported placeholders:
    | - {tenantId}: replaced by the current tenant id (required)
    | - {userId}:   numeric user id
    | - *:          single path segment wildcard (no slashes)
    |
    | Each entry can be a string pattern or array with a 'pattern' key to allow
    | future extensibility.
    */
    'allowed_paths' => [
        'tenants/{tenantId}/users/{userId}/avatars/',
        'tenants/{tenantId}/users/{userId}/avatars/*/conversions/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache & TTL defaults for media serving
    |--------------------------------------------------------------------------
    |
    | These values are the single source of truth for cache headers and
    | temporary URL lifetimes used by media/avatars serving.
    |
    */
    'local_max_age_seconds' => (int) env('MEDIA_LOCAL_MAX_AGE_SECONDS', 86400),
    's3_temporary_url_ttl_seconds' => (int) env('MEDIA_S3_TEMPORARY_URL_TTL_SECONDS', 900),
];
