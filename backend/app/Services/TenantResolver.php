<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Str;

class TenantResolver
{
    public const CONTEXT_PLATFORM = 'platform';

    public const CONTEXT_TENANT = 'tenant';

    public const CONTEXT_NONE = 'none';

    protected ?Tenant $current = null;

    protected string $context = self::CONTEXT_NONE;

    public function __construct(private readonly TenantDatabaseManager $databases)
    {
    }

    /**
     * Resolve a tenant from a hostname, or null when no tenant matches.
     *
     * Resolution order:
     *  1. Exact match against the tenant_domains table (covers custom domains
     *     and explicitly registered subdomains).
     *  2. Subdomain of the platform domain matched against tenants.slug.
     */
    public function resolve(?string $host = null): ?Tenant
    {
        $host = $this->normalizeHost($host);

        if (! $host) {
            return null;
        }

        $domain = TenantDomain::with('tenant')
            ->where('domain', $host)
            ->first();

        if ($domain?->tenant) {
            return $domain->tenant;
        }

        $platformDomain = $this->normalizeHost(config('tenancy.platform_domain'));

        if ($platformDomain && str_ends_with($host, '.'.$platformDomain)) {
            $subdomain = Str::beforeLast($host, '.'.$platformDomain);

            if ($subdomain !== '' && ! str_contains($subdomain, '.')) {
                return Tenant::query()->where('slug', $subdomain)->first();
            }
        }

        return null;
    }

    /**
     * Whether a hostname belongs to the Vee-Care platform (control plane).
     */
    public function isPlatformHost(?string $host = null): bool
    {
        $host = $this->normalizeHost($host);

        if (! $host) {
            return false;
        }

        $platformDomain = $this->normalizeHost(config('tenancy.platform_domain'));

        if (! $platformDomain) {
            return false;
        }

        if ($host === $platformDomain) {
            return true;
        }

        if (! str_ends_with($host, '.'.$platformDomain)) {
            return false;
        }

        $subdomain = Str::beforeLast($host, '.'.$platformDomain);

        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return false;
        }

        return in_array($subdomain, config('tenancy.platform_subdomains'), true);
    }

    /**
     * Enter a tenant context for the current request/process.
     */
    public function setCurrent(Tenant $tenant): void
    {
        $this->current = $tenant;
        $this->context = self::CONTEXT_TENANT;
        $this->databases->connect($tenant);
        $this->forgetCachedGuards();
    }

    /**
     * Enter the platform context (control database as default connection).
     */
    public function setPlatformContext(): void
    {
        $this->current = null;
        $this->context = self::CONTEXT_PLATFORM;
        $this->databases->disconnect();
        $this->forgetCachedGuards();
    }

    public function clear(): void
    {
        $this->current = null;
        $this->context = self::CONTEXT_NONE;
        $this->databases->disconnect();
        $this->forgetCachedGuards();
    }

    /**
     * Drop cached auth guards so a user resolved in one tenant (or on the
     * platform) is never reused inside another tenant context. Safe in FPM
     * (fresh process per request) and required for long-running processes.
     */
    protected function forgetCachedGuards(): void
    {
        app('auth')->forgetGuards();
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function context(): string
    {
        return $this->context;
    }

    public function isTenantContext(): bool
    {
        return $this->context === self::CONTEXT_TENANT && $this->current !== null;
    }

    public function isPlatformContext(): bool
    {
        return $this->context === self::CONTEXT_PLATFORM;
    }

    protected function normalizeHost(?string $host): string
    {
        if ($host === null) {
            $host = request()->getHost() ?? '';
        }

        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }
}
