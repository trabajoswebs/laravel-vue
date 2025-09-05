<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vite Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for Vite development server.
    | These settings help configure CSP headers for Vite development.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Development Server URLs
    |--------------------------------------------------------------------------
    |
    | Configure the URLs for Vite development server.
    | Vite can use different ports, so we include multiple options.
    |
    */

    'dev_server_urls' => [
        env('VITE_DEV_SERVER_URL', 'http://127.0.0.1:5173'),
        'http://127.0.0.1:5174',
        'http://127.0.0.1:5175',
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Port
    |--------------------------------------------------------------------------
    |
    | The default port that Vite will try to use.
    |
    */

    'default_port' => env('VITE_PORT', 5173),

    /*
    |--------------------------------------------------------------------------
    | CSP Directives
    |--------------------------------------------------------------------------
    |
    | Configure which CSP directives should include Vite URLs.
    |
    */

    'csp_directives' => [
        'script-src',
        'style-src',
        'connect-src',
    ],

];

