<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->organization_id && ! $request->user()?->isRole('super_admin')) {
            abort(403, 'An organization context is required.');
        }

        return $next($request);
    }
}
