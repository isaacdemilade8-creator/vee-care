<?php

namespace App\Http\Middleware;

use App\Services\TenantConfigurationService;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantConfigurationService $configuration,
    ) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isRole(...$roles)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        // A hospital may disable an optional role (e.g. pharmacist). Block that
        // role from every role-gated route for the resolved tenant. Deactivated
        // accounts are blocked the same way. Platform contexts resolve no
        // tenant, so platform roles are never affected.
        $tenant = $this->resolver->current();

        if ($tenant) {
            if (! $user->is_active) {
                abort(403, 'Your account has been deactivated.');
            }

            if (! $this->configuration->isRoleEnabled($tenant, (string) $user->role)) {
                abort(403, 'Your role is not enabled at this hospital.');
            }
        }

        return $next($request);
    }
}
