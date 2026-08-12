<?php

namespace App\Http\Middleware;

use App\Services\TenantConfigurationService;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block a route when the resolved tenant has the requested module disabled.
 *
 * Required modules always pass (a stale row must never hide a mandatory
 * capability); platform/unknown contexts pass through untouched. Multiple
 * module keys are treated as OR — the route is allowed when any of them is
 * enabled — so shared routes never need separate registrations.
 */
class EnsureTenantModuleEnabled
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantConfigurationService $configuration,
    ) {}

    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $tenant = $this->resolver->current();

        if (! $tenant) {
            return $next($request);
        }

        $enabled = $this->configuration->modules($tenant);
        $registry = config('tenant-defaults.modules', []);

        foreach ($modules as $module) {
            $meta = $enabled[$module] ?? null;

            if (! $meta) {
                continue;
            }

            $isRequired = (bool) ($registry[$module]['required'] ?? false);

            if ($meta['enabled'] || $isRequired) {
                return $next($request);
            }
        }

        abort(403, 'This feature is not enabled for your hospital.');
    }
}
