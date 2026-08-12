<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, safe representation of a tenant used to bootstrap the frontend.
 *
 * Deliberately excludes every database/internal field (database_name, host,
 * port, username, credentials, raw settings, role configuration, domains).
 * Only safe identity, branding and the enabled-module flags (with required
 * markers) the public hospital experience needs are exposed. Branding reads
 * the dedicated tenant_branding table (falling back to the legacy
 * `settings.branding` JSON), and module flags fall back to the registry
 * defaults when a legacy tenant has no rows yet — this endpoint never writes.
 *
 * @mixin Tenant
 */
class TenantContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'branding' => $this->publicBranding(),
            'modules' => $this->publicModules(),
        ];
    }

    /**
     * @return array{logo: string|null, favicon: string|null, primaryColor: string|null, secondaryColor: string|null, accentColor: string|null, fontFamily: string|null}
     */
    protected function publicBranding(): array
    {
        $legacy = is_array($this->settings['branding'] ?? null) ? $this->settings['branding'] : [];

        $row = $this->branding;

        return [
            'logo' => $row?->logo ?? $legacy['logo'] ?? null,
            'favicon' => $row?->favicon ?? $legacy['favicon'] ?? null,
            'primaryColor' => $row?->primary_color ?? $legacy['primary_color'] ?? null,
            'secondaryColor' => $row?->secondary_color ?? $legacy['secondary_color'] ?? null,
            'accentColor' => $row?->accent_color ?? $legacy['accent_color'] ?? null,
            'fontFamily' => $row?->font_family ?? $legacy['font_family'] ?? null,
        ];
    }

    /**
     * Enabled-module map with required markers. Uses stored rows when present;
     * legacy tenants without rows inherit the registry defaults (read-only —
     * never writes). Required modules are always reported enabled even if a
     * stale row were ever disabled, so the public UI never hides a mandatory
     * capability.
     *
     * @return array<string, array{enabled: bool, required: bool}>
     */
    protected function publicModules(): array
    {
        $defaults = config('tenant-defaults.modules', []);
        $stored = $this->modules?->pluck('enabled', 'module');

        $modules = [];

        foreach ($defaults as $key => $meta) {
            $required = (bool) ($meta['required'] ?? false);
            $enabled = $stored && $stored->has($key)
                ? (bool) $stored[$key]
                : (bool) $meta['default_enabled'];

            $modules[$key] = [
                'enabled' => $enabled || $required,
                'required' => $required,
            ];
        }

        return $modules;
    }
}
