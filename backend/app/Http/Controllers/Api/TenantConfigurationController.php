<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantConfigurationResource;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Services\TenantConfigurationService;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hospital-admin configuration surface (tenant-facing).
 *
 * Lets a hospital administrator manage their own Vee-Care experience from the
 * hospital dashboard. The active tenant is resolved authoritatively from the
 * request host by ResolveTenant/TenantResolver — never from a client-supplied
 * tenant id — and the route is restricted to the hospital_admin role, so a
 * tenant can only ever read/write its own configuration. Writes land in the
 * control database through the same service the platform uses, and are audited
 * on the control plane.
 *
 * Only the hospital_admin tenant role can reach these endpoints; platform
 * roles are handled by the separate platform configuration surface.
 */
class TenantConfigurationController extends Controller
{
    public function __construct(private readonly TenantConfigurationService $config) {}

    public function show(Request $request, TenantResolver $resolver): JsonResponse
    {
        $tenant = $this->resolveTenant($resolver);
        $this->config->ensureDefaults($tenant);

        return response()->json($this->payload($request, $tenant));
    }

    public function update(Request $request, TenantResolver $resolver): JsonResponse
    {
        $tenant = $this->resolveTenant($resolver);
        $this->config->ensureDefaults($tenant);

        $data = $request->validate(array_merge($this->config->rules(), [
            'name' => ['sometimes', 'string', 'max:255', 'min:2'],
        ]));

        // Laravel's validated() drops nested keys that match no rule, so an
        // unknown settings key silently vanishes before the allowlist check.
        // Re-attach the raw sections so unknown keys stay visible.
        foreach (['branding', 'modules', 'roles', 'settings'] as $section) {
            if ($request->has($section)) {
                $data[$section] = array_merge($request->input($section, []), $data[$section] ?? []);
            }
        }

        $this->config->assertKnownSettings($data);
        $this->config->assertValidTenantRoles($data['roles'] ?? []);

        $changed = $this->config->applyChanges($tenant, $data);

        if (isset($data['name']) && $data['name'] !== $tenant->name) {
            $tenant->update(['name' => trim($data['name'])]);
            $changed['categories'][] = 'identity';
            $changed['keys']['identity'][] = 'name';
        }

        if ($changed['categories'] !== []) {
            $this->audit($request, $tenant, $changed);
        }

        return response()->json($this->payload($request, $tenant->fresh()));
    }

    /**
     * The resolved tenant must exist and be active. The resolver was already
     * primed by the ResolveTenant middleware from the request host, so this is
     * never a client-controlled id.
     */
    protected function resolveTenant(TenantResolver $resolver): Tenant
    {
        $tenant = $resolver->current();

        if (! $tenant || ! $tenant->isActive()) {
            abort(403, 'No active hospital context for this request.');
        }

        return $tenant;
    }

    /**
     * The hospital-admin view of the configuration: identity (name) plus the
     * shared configuration sections. Never exposes database/internal fields or
     * credentials.
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request, Tenant $tenant): array
    {
        $config = (new TenantConfigurationResource($tenant))->resolve($request);

        return ['name' => $tenant->name] + $config;
    }

    /**
     * Record a control-plane audit event. The platform_user_id stays null for
     * hospital-initiated changes; the acting hospital administrator is captured
     * (non-sensitive identity only) in the metadata. Never values, passwords,
     * tokens or database credentials.
     *
     * @param  array{categories: list<string>, keys: array<string, list<string>>}  $changed
     */
    protected function audit(Request $request, Tenant $tenant, array $changed): PlatformAuditLog
    {
        $actor = $request->user();

        return PlatformAuditLog::query()->create([
            'event' => 'tenant.configuration.updated',
            'platform_user_id' => null,
            'tenant_id' => $tenant->id,
            'metadata' => [
                'categories' => $changed['categories'],
                'keys' => $changed['keys'],
                'actor' => $actor ? [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'role' => $actor->role,
                ] : null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
