<?php

use App\Http\Middleware\EnsureTenantModuleEnabled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'tenant' => ResolveTenant::class,
            'module' => EnsureTenantModuleEnabled::class,
        ]);

        // Trust X-Forwarded-For (audit-log client IPs) and X-Forwarded-Proto
        // (secure scheme behind a TLS-terminating reverse proxy) ONLY from the
        // proxies listed in TRUSTED_PROXIES (comma-separated IPs/CIDRs). We
        // deliberately do NOT trust X-Forwarded-Host: tenant identity is derived
        // from the Host header, and letting a forwarded header override it would
        // let any client that reaches the app directly impersonate a hospital.
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->api(prepend: [
            ResolveTenant::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
