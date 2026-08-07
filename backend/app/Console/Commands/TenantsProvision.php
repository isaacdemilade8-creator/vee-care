<?php

namespace App\Console\Commands;

use App\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class TenantsProvision extends Command
{
    protected $signature = 'tenants:provision
        {name : Display name of the tenant (e.g. "Hospital One")}
        {--email= : Initial hospital administrator email}
        {--password= : Initial hospital administrator password}
        {--type=hospital : Tenant type (clinic|hospital|lab|pharmacy)}
        {--plan=starter : Subscription plan (starter|growth|enterprise)}
        {--currency=USD : Default currency code}
        {--subdomain= : Custom subdomain (defaults to a slug of the name)}';

    protected $description = 'Provision a new tenant: database, schema, organization and administrator.';

    public function handle(TenantProvisioner $provisioner): int
    {
        $name = (string) $this->argument('name');

        $email = $this->option('email') ?: null;
        $password = $this->option('password') ?: null;

        if (($email && ! $password) || ($password && ! $email)) {
            throw ValidationException::withMessages([
                'email' => 'Both --email and --password are required together.',
            ]);
        }

        $subdomain = $this->option('subdomain') ?: null;

        if ($subdomain && ! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $subdomain)) {
            throw ValidationException::withMessages([
                'subdomain' => 'The subdomain may only contain lowercase letters, numbers and hyphens.',
            ]);
        }

        $this->info("Provisioning tenant \"{$name}\" ...");

        $tenant = $provisioner->provision($name, [
            'email' => $email,
            'password' => $password,
            'type' => (string) $this->option('type'),
            'plan' => (string) $this->option('plan'),
            'currency' => (string) $this->option('currency'),
            'slug' => $subdomain,
        ]);

        $domain = $tenant->primaryDomain() ?? 'no domain registered';

        $this->info("Tenant [{$tenant->slug}] provisioned.");
        $this->line("  Database: {$tenant->database_name}");
        $this->line("  Domain:   {$domain}");
        $this->line("  Status:   {$tenant->status}");

        return self::SUCCESS;
    }
}
