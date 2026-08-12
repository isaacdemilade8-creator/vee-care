<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('VITE_FRONTEND_URL', 'http://localhost:5173'),
    ],

    // Allows every tenant subdomain of the platform domain (e.g.
    // hospital-one.vee-care.test) plus localhost in development.
    'allowed_origins_patterns' => array_values(array_filter([
        env('APP_ENV') !== 'production' ? '#^http://localhost(:\d+)?$#' : null,
        env('APP_ENV') !== 'production' ? '#^http://127\.0\.0\.1(:\d+)?$#' : null,
        // Bare platform apex host (e.g. http://vee-care.test:5173) - the
        // platform frontend is served from the apex domain itself.
        env('TENANT_PLATFORM_DOMAIN', env('APP_DOMAIN', 'vee-care.test'))
            ? '#^https?://'.preg_quote(env('TENANT_PLATFORM_DOMAIN', env('APP_DOMAIN', 'vee-care.test')), '#').'(:\d+)?$#'
            : null,
        // Every tenant subdomain of the platform domain (e.g.
        // hospital-one.vee-care.test), never a wildcard across hosts.
        env('TENANT_PLATFORM_DOMAIN', env('APP_DOMAIN', 'vee-care.test'))
            ? '#^https?://[a-z0-9-]+\.'.preg_quote(env('TENANT_PLATFORM_DOMAIN', env('APP_DOMAIN', 'vee-care.test')), '#').'(:\d+)?$#'
            : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
