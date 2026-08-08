<?php

namespace App\Services;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Database\Seeders\TenantSeeder;
use RuntimeException;

class TenantProvisioner
{
    public function __construct(private readonly TenantDatabaseManager $databases)
    {
    }

    /**
     * Provision a complete tenant:
     *
     *  1. Create the control-plane tenant record + primary domain.
     *  2. Create the physical database.
     *  3. Run the tenant migrations.
     *  4. Seed the default organization, branch and hospital administrator.
     *  5. Mark the tenant active.
     */
    public function provision(string $name, array $options = []): Tenant
    {
        $email = $options['email'] ?? null;
        $password = $options['password'] ?? null;

        $slug = $options['slug'] ?? Tenant::generateSlug($name);
        $slug = strtolower(trim($slug));

        $this->assertUsableSlug($slug);

        $databaseName = $options['database_name'] ?? $this->buildDatabaseName($slug);
        $type = $options['type'] ?? 'hospital';
        $plan = $options['plan'] ?? 'starter';
        $currency = $options['currency'] ?? 'USD';
        $settings = $options['settings'] ?? ['locale' => 'en', 'timezone' => 'UTC'];

        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'plan' => $plan,
            'status' => TenantStatus::Provisioning->value,
            'currency' => $currency,
            'database_name' => $databaseName,
            'database_host' => $options['database_host'] ?? null,
            'database_port' => $options['database_port'] ?? null,
            'database_username' => $options['database_username'] ?? null,
            'database_password' => $options['database_password'] ?? null,
            'settings' => $settings,
        ]);

        try {
            $this->databases->createDatabase($tenant);
            $this->databases->migrate($tenant);
            app(TenantSeeder::class)->run($tenant, $email, $password);
            $this->registerPrimaryDomain($tenant, $slug);

            $tenant->update(['status' => TenantStatus::Active->value]);
        } catch (\Throwable $e) {
            $tenant->update(['status' => TenantStatus::Failed->value]);

            throw new RuntimeException("Tenant provisioning failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->databases->disconnect();
        }

        return $tenant->fresh();
    }

    /**
     * Reject slugs that collide with the control plane's own hostnames.
     */
    protected function assertUsableSlug(string $slug): void
    {
        if (in_array($slug, config('tenancy.platform_subdomains', []), true)) {
            throw new RuntimeException("Slug [{$slug}] is a reserved platform subdomain.");
        }
    }

    /**
     * Register the tenant's primary subdomain against the platform domain.
     */
    protected function registerPrimaryDomain(Tenant $tenant, string $slug): void
    {
        $platformDomain = config('tenancy.platform_domain');

        if (! $platformDomain) {
            return;
        }

        $domain = $slug.'.'.$platformDomain;

        $tenant->domains()->updateOrCreate(
            ['domain' => $domain],
            ['is_primary' => true],
        );
    }

    protected function buildDatabaseName(string $slug): string
    {
        return config('tenancy.database.prefix').str_replace('-', '_', $slug);
    }
}
