<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantContextResource;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public tenant bootstrap endpoint.
 *
 * Resolves the current host (via the ResolveTenant middleware that runs on
 * every API request) and returns only the safe, public tenant context. This
 * lets the frontend load hospital branding on the login page without any
 * authenticated session. No credentials, database details or internal
 * configuration are ever exposed.
 */
class TenantContextController extends Controller
{
    public function show(Request $request, TenantResolver $resolver): JsonResponse
    {
        // In local development the SPA may call the API at 127.0.0.1 while the
        // page itself is served from a tenant subdomain. Let the browser
        // declare its host so the tenant can still be resolved. Production
        // never honors a client-declared host: the request Host is the truth.
        if (! app()->isProduction() && $resolver->isPlatformHost($request->getHost())) {
            $declared = $request->query('host');

            if (is_string($declared) && $declared !== '') {
                $tenant = $resolver->resolve($declared);

                if ($tenant && $tenant->isActive()) {
                    return response()->json([
                        'context' => 'tenant',
                        'tenant' => new TenantContextResource($tenant),
                    ]);
                }
            }
        }

        if ($resolver->isPlatformContext()) {
            return response()->json([
                'context' => 'platform',
                'tenant' => null,
            ]);
        }

        $tenant = $resolver->current();

        if (! $tenant) {
            return response()->json([
                'context' => 'none',
                'tenant' => null,
            ]);
        }

        return response()->json([
            'context' => 'tenant',
            'tenant' => new TenantContextResource($tenant),
        ]);
    }
}
