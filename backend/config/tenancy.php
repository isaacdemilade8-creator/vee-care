<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform / Apex Domain
    |--------------------------------------------------------------------------
    |
    | The domain that hosts the Vee-Care control plane (platform administration).
    | Tenant subdomains are resolved relative to this domain, e.g.
    |
    |     vee-care.test                 -> platform (control database)
    |     hospital-one.vee-care.test    -> Hospital One (tenant database)
    |
    */

    'platform_domain' => env('TENANT_PLATFORM_DOMAIN', env('APP_DOMAIN', 'vee-care.test')),

    /*
    |--------------------------------------------------------------------------
    | Platform Subdomains
    |--------------------------------------------------------------------------
    |
    | Additional subdomains of the platform domain that are treated as
    | platform hosts (control database) rather than tenant hosts.
    |
    */

    'platform_subdomains' => array_filter(explode(',', (string) env('TENANT_PLATFORM_SUBDOMAINS', 'api,admin,www'))),

    /*
    |--------------------------------------------------------------------------
    | Tenant Database
    |--------------------------------------------------------------------------
    |
    | The default settings used when creating tenant databases. Individual
    | tenants may override host/port/username/password via their own record
    | (encrypted at rest). TENANT_DB_DRIVER may be set to "sqlite" for local
    | development or testing without a database server.
    |
    */

    'database' => [
        'driver' => env('TENANT_DB_DRIVER'),
        'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('TENANT_DB_PORT', env('DB_PORT', '3306')),
        'username' => env('TENANT_DB_USERNAME', env('DB_USERNAME', 'root')),
        'password' => env('TENANT_DB_PASSWORD', env('DB_PASSWORD', '')),
        'prefix' => env('TENANT_DB_PREFIX', 'vee_care_tenant_'),
        'sqlite_path' => env('TENANT_DB_PATH', database_path('tenants')),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Migration Path
    |--------------------------------------------------------------------------
    |
    | Relative path (from the Laravel base path) to the migrations that make
    | up a tenant's schema. These run against each tenant database and are
    | tracked in that database's own migrations table.
    |
    */

    'migrations_path' => 'database/migrations/tenant',

    /*
    |--------------------------------------------------------------------------
    | Hospital Admin Invitation
    |--------------------------------------------------------------------------
    |
    | Single-use invitation tokens issued when a hospital application is
    | approved. Only the token digest is stored; the plaintext token is
    | delivered to the approver once and must be redeemed before expiry.
    |
    */

    'invitation' => [
        'expiry_days' => env('TENANT_INVITATION_EXPIRY_DAYS', 7),
    ],
];
