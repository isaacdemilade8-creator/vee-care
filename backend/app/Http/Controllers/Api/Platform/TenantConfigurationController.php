<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantConfigurationResource;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Services\TenantConfigurationService;
use Illuminate\Http\Request;

/**
 * Platform tenant configuration.
 *
 * A single, unified configuration surface: GET returns the full model
 * (name, branding, modules, roles, settings); PATCH accepts any combination of
 * those sections in one request. Semantic rules (required modules/roles, valid
 * tenant roles only) are enforced by TenantConfigurationService; changes are
 * audited on the control plane.
 */
class TenantConfigurationController extends Controller
{
    public function __construct(private readonly TenantConfigurationService $config) {}

    public function show(Tenant $tenant): TenantConfigurationResource
    {
        $this->config->ensureDefaults($tenant);

        return new TenantConfigurationResource($tenant);
    }

    public function update(Request $request, Tenant $tenant): TenantConfigurationResource
    {
        $this->config->ensureDefaults($tenant);

        $data = $request->validate($this->config->rules());

        // Laravel's validated() drops nested keys that match no rule, so an
        // unknown settings key (e.g. `theme`) silently vanishes before the
        // allowlist check below. Re-attach the raw sections so unknown keys
        // stay visible; validated values still win where a rule did run.
        foreach (['branding', 'modules', 'roles', 'settings'] as $section) {
            if ($request->has($section)) {
                $data[$section] = array_merge($request->input($section, []), $data[$section] ?? []);
            }
        }

        $this->config->assertKnownSettings($data);
        $this->config->assertValidTenantRoles($data['roles'] ?? []);

        $changed = $this->config->applyChanges($tenant, $data);

        if ($changed['categories'] !== []) {
            $this->audit($request, $tenant, $changed);
        }

        return new TenantConfigurationResource($tenant->fresh());
    }

    /**
     * Record a control-plane audit event. Metadata contains only the changed
     * categories and keys — never values (which could be noise) and never
     * secrets.
     *
     * @param  array{categories: list<string>, keys: array<string, list<string>>}  $changed
     */
    protected function audit(Request $request, Tenant $tenant, array $changed): PlatformAuditLog
    {
        return PlatformAuditLog::query()->create([
            'event' => 'tenant.configuration.updated',
            'platform_user_id' => $request->user()?->id,
            'tenant_id' => $tenant->id,
            'metadata' => [
                'categories' => $changed['categories'],
                'keys' => $changed['keys'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
