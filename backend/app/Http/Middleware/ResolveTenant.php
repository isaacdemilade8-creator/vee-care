<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($this->resolver->isPlatformHost($host)) {
            $this->resolver->setPlatformContext();

            return $next($request);
        }

        $tenant = $this->resolver->resolve($host);

        if (! $tenant) {
            abort(404, 'Unknown tenant domain.');
        }

        if (! $tenant->isActive()) {
            abort(403, 'This tenant is not active.');
        }

        $this->resolver->setCurrent($tenant);

        return $next($request);
    }
}
