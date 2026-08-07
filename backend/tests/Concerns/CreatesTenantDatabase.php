<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Services\TenantDatabaseManager;
use App\Services\TenantProvisioner;
use App\Services\TenantResolver;
use Illuminate\Support\Str;

trait CreatesTenantDatabase
{
    /**
     * Run the control-plane migrations on the "control" connection.
     */
    protected function migrateControlDatabase(): void
    {
        $this->artisan('migrate', ['--database' => 'control', '--force' => true])->run();
    }

    /**
     * Provision a tenant (database + schema + organization + admin).
     */
    protected function provisionTenant(string $slug, array $options = []): Tenant
    {
        return app(TenantProvisioner::class)->provision(
            Str::title(str_replace('-', ' ', $slug)).' Tenant',
            [
                'slug' => $slug,
                'email' => "admin@{$slug}.vee-care.test",
                'password' => 'password123',
                ...$options,
            ],
        );
    }

    /**
     * Point the default connection at a tenant database.
     */
    protected function connectToTenant(Tenant $tenant): void
    {
        app(TenantResolver::class)->setCurrent($tenant);
    }

    /**
     * Point the default connection back at the control database.
     */
    protected function disconnectFromTenant(): void
    {
        app(TenantDatabaseManager::class)->disconnect();
    }

    /**
     * Remove any tenant sqlite files created during a test.
     */
    protected function cleanupTenantDatabases(): void
    {
        $directory = config('tenancy.database.sqlite_path');

        if (! is_dir($directory)) {
            return;
        }

        foreach (glob(rtrim($directory, '/\\').'/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }
    }
}
