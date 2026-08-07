<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
            'status' => 'provisioning',
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
            $this->seedOrganization($tenant, $slug, $type, $plan, $currency, $settings);

            if ($email && $password) {
                $this->seedAdministrator($tenant, $email, $password);
            }

            $this->registerPrimaryDomain($tenant, $slug);

            $tenant->update(['status' => 'active']);
        } catch (\Throwable $e) {
            $tenant->update(['status' => 'failed']);

            throw new RuntimeException("Tenant provisioning failed: {$e->getMessage()}", 0, $e);
        } finally {
            $this->databases->disconnect();
        }

        return $tenant->fresh();
    }

    /**
     * Seed the default organization + main branch inside the tenant database.
     */
    protected function seedOrganization(Tenant $tenant, string $slug, string $type, string $plan, string $currency, array $settings): void
    {
        $organization = Organization::query()->create([
            'name' => $tenant->name,
            'slug' => $slug,
            'type' => $type,
            'plan' => $plan,
            'status' => 'active',
            'currency' => $currency,
            'settings' => $settings,
        ]);

        Branch::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Main Branch',
        ]);
    }

    /**
     * Seed the initial hospital administrator inside the tenant database.
     */
    protected function seedAdministrator(Tenant $tenant, string $email, string $password): User
    {
        $organization = Organization::query()->first();
        $branch = Branch::query()->where('organization_id', $organization?->id)->first();

        $user = User::query()->create([
            'organization_id' => $organization?->id,
            'branch_id' => $branch?->id,
            'name' => $tenant->name.' Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);

        return $user;
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
