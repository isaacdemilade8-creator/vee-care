<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantModule;
use App\Models\TenantRoleConfiguration;
use App\Rules\SafeAssetUrl;
use App\Rules\SafeColor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the tenant configuration model (control database).
 *
 * A tenant has four configuration concerns:
 *   - branding           (tenant_branding, one row; null inherits Vee-Care)
 *   - modules            (tenant_modules, one row per registered module)
 *   - roles              (tenant_role_configuration, one row per tenant role)
 *   - general settings   (tenants.settings JSON, strict allowlist)
 *
 * The registries and defaults come from config/tenant-defaults.php so backend
 * controllers, seeders and provisioning never duplicate values. Required
 * modules/roles are enforced here regardless of what a request submits.
 */
class TenantConfigurationService
{
    /**
     * Ensure a tenant has a complete configuration row set. Missing rows are
     * created from the defaults; existing rows are never overwritten. Also
     * backfills legacy branding stored in `settings.branding`.
     */
    public function ensureDefaults(Tenant $tenant): void
    {
        $this->brandingRow($tenant);
        $this->syncModuleRows($tenant);
        $this->syncRoleRows($tenant);
    }

    public function branding(Tenant $tenant): array
    {
        $row = $this->brandingRow($tenant);

        return [
            'logo' => $row->logo,
            'favicon' => $row->favicon,
            'primary_color' => $row->primary_color,
            'secondary_color' => $row->secondary_color,
            'accent_color' => $row->accent_color,
            'font_family' => $row->font_family,
        ];
    }

    /**
     * @return array<string, array{enabled: bool, required: bool, name: string, description: string}>
     */
    public function modules(Tenant $tenant): array
    {
        $this->syncModuleRows($tenant);

        $states = $tenant->modules()->pluck('enabled', 'module')->map(fn (bool $enabled) => (bool) $enabled);

        $result = [];

        foreach ($this->registry()['modules'] as $key => $meta) {
            $result[$key] = [
                'enabled' => $states[$key] ?? (bool) $meta['default_enabled'],
                'required' => (bool) $meta['required'],
                'name' => $meta['name'],
                'description' => $meta['description'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{enabled: bool, required: bool, label: string}>
     */
    public function roles(Tenant $tenant): array
    {
        $this->syncRoleRows($tenant);

        $states = $tenant->roleConfiguration()->pluck('enabled', 'role')->map(fn (bool $enabled) => (bool) $enabled);

        $result = [];

        foreach ($this->registry()['roles'] as $role => $meta) {
            $result[$role] = [
                'enabled' => $states[$role] ?? true,
                'required' => (bool) $meta['required'],
                'label' => $meta['label'],
            ];
        }

        return $result;
    }

    /**
     * Whether a tenant role is currently available to a hospital. Required
     * roles can never be disabled; optional roles are read from the stored row
     * (defaulting to enabled when a legacy tenant has no row yet).
     */
    public function isRoleEnabled(Tenant $tenant, string $role): bool
    {
        $meta = $this->registry()['roles'][$role] ?? null;

        if (! $meta) {
            return false;
        }

        if ((bool) $meta['required']) {
            return true;
        }

        $this->syncRoleRows($tenant);

        $row = $tenant->roleConfiguration()->where('role', $role)->first();

        return $row ? (bool) $row->enabled : true;
    }

    /**
     * General settings, merged over the defaults and restricted to the
     * allowlist so stale/unknown keys never surface.
     */
    public function settings(Tenant $tenant): array
    {
        $defaults = $this->registry()['settings']['defaults'];
        $stored = is_array($tenant->settings) ? $tenant->settings : [];

        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    /**
     * Persist branding values (already validated). Null clears a field back to
     * "inherit Vee-Care default". Returns the keys that actually changed.
     *
     * @return list<string>
     */
    public function updateBranding(Tenant $tenant, array $values): array
    {
        $row = $this->brandingRow($tenant);
        $changed = [];

        foreach (['logo', 'favicon', 'primary_color', 'secondary_color', 'accent_color', 'font_family'] as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $normalized = $values[$key] === '' ? null : $values[$key];

            if ($row->getAttribute($key) !== $normalized) {
                $row->setAttribute($key, $normalized);
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            $row->save();
        }

        return $changed;
    }

    /**
     * Apply module states. Required modules can never be disabled; unknown
     * module keys are ignored. Returns the keys that changed.
     *
     * @param  array<string, bool>  $states
     * @return list<string>
     */
    public function updateModules(Tenant $tenant, array $states): array
    {
        $this->syncModuleRows($tenant);
        $changed = [];

        foreach ($states as $key => $enabled) {
            if (! isset($this->registry()['modules'][$key])) {
                throw ValidationException::withMessages([
                    "modules.{$key}" => ['Unknown module.'],
                ]);
            }

            $meta = $this->registry()['modules'][$key];
            $wanted = (bool) $enabled;

            if ($meta['required'] && ! $wanted) {
                throw ValidationException::withMessages([
                    "modules.{$key}" => ["The {$key} module is required and cannot be disabled."],
                ]);
            }

            $row = $tenant->modules()->where('module', $key)->first();

            if ($row && (bool) $row->enabled !== $wanted) {
                $row->update(['enabled' => $wanted]);
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Apply role states. Required roles can never be disabled; platform roles
     * are never valid tenant roles and are rejected. Returns changed keys.
     *
     * @param  array<string, bool>  $states
     * @return list<string>
     */
    public function updateRoles(Tenant $tenant, array $states): array
    {
        $this->syncRoleRows($tenant);
        $changed = [];

        foreach ($states as $role => $enabled) {
            if (! isset($this->registry()['roles'][$role])) {
                throw ValidationException::withMessages([
                    "roles.{$role}" => ['Unknown tenant role.'],
                ]);
            }

            $meta = $this->registry()['roles'][$role];
            $wanted = (bool) $enabled;

            if ($meta['required'] && ! $wanted) {
                throw ValidationException::withMessages([
                    "roles.{$role}" => ['This role is required and cannot be disabled.'],
                ]);
            }

            $row = $tenant->roleConfiguration()->where('role', $role)->first();

            if ($row && (bool) $row->enabled !== $wanted) {
                $row->update(['enabled' => $wanted]);
                $changed[] = $role;
            }
        }

        return $changed;
    }

    /**
     * Apply general settings (validated against the allowlist). Change
     * detection compares against the effective settings (defaults already
     * merged) so submitting a value identical to the current effective value
     * is a no-op and is never stored or audited. Returns the keys that changed.
     *
     * @return list<string>
     */
    public function updateSettings(Tenant $tenant, array $values): array
    {
        $effective = $this->settings($tenant);
        $stored = is_array($tenant->settings) ? $tenant->settings : [];
        $changed = [];

        foreach ($values as $key => $value) {
            if (($effective[$key] ?? null) !== $value) {
                $changed[] = $key;
                $stored[$key] = $value;
            }
        }

        if ($changed !== []) {
            $tenant->update(['settings' => $stored]);
        }

        return $changed;
    }

    /**
     * Validation rules for configuration payloads. Shared by the platform and
     * hospital-admin surfaces so both accept exactly the same shape, values
     * and allowlists.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branding' => ['sometimes', 'array'],
            'branding.logo' => ['nullable', new SafeAssetUrl],
            'branding.favicon' => ['nullable', new SafeAssetUrl],
            'branding.primary_color' => ['nullable', new SafeColor],
            'branding.secondary_color' => ['nullable', new SafeColor],
            'branding.accent_color' => ['nullable', new SafeColor],
            'branding.font_family' => ['nullable', Rule::in(config('tenant-defaults.fonts', []))],

            'modules' => ['sometimes', 'array'],
            'modules.*' => ['boolean'],

            'roles' => ['sometimes', 'array'],
            'roles.*' => ['boolean'],

            'settings' => ['sometimes', 'array'],
            'settings.locale' => ['sometimes', Rule::in(config('tenant-defaults.settings.locales', []))],
            'settings.timezone' => ['sometimes', Rule::in(config('tenant-defaults.settings.timezones', []))],
            'settings.date_format' => ['sometimes', Rule::in(config('tenant-defaults.settings.date_formats', []))],
            'settings.time_format' => ['sometimes', Rule::in(config('tenant-defaults.settings.time_formats', []))],
            'settings.default_appointment_duration' => ['sometimes', 'integer', 'between:5,240'],
        ];
    }

    /**
     * Reject settings keys outside the strict allowlist. Unknown keys are
     * never silently dropped: the client gets a 422 naming them.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertKnownSettings(array $data): void
    {
        if (! isset($data['settings'])) {
            return;
        }

        $allowed = array_keys(config('tenant-defaults.settings.defaults', []));
        $unknown = array_diff(array_keys($data['settings']), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => ['Unsupported setting: '.implode(', ', $unknown).'.'],
            ]);
        }
    }

    /**
     * Apply the (already validated) configuration sections to a tenant.
     * Returns which categories and keys actually changed — never values, so
     * callers can audit safely without storing noisy diffs.
     *
     * @param  array<string, mixed>  $data
     * @return array{categories: list<string>, keys: array<string, list<string>>}
     */
    public function applyChanges(Tenant $tenant, array $data): array
    {
        $categories = [];
        $keys = [];

        if (isset($data['branding'])) {
            $changedKeys = $this->updateBranding($tenant, $data['branding']);

            if ($changedKeys !== []) {
                $categories[] = 'branding';
                $keys['branding'] = $changedKeys;
            }
        }

        if (isset($data['modules'])) {
            $changedKeys = $this->updateModules($tenant, $data['modules']);

            if ($changedKeys !== []) {
                $categories[] = 'modules';
                $keys['modules'] = $changedKeys;
            }
        }

        if (isset($data['roles'])) {
            $changedKeys = $this->updateRoles($tenant, $data['roles']);

            if ($changedKeys !== []) {
                $categories[] = 'roles';
                $keys['roles'] = $changedKeys;
            }
        }

        if (isset($data['settings'])) {
            $changedKeys = $this->updateSettings($tenant, $data['settings']);

            if ($changedKeys !== []) {
                $categories[] = 'settings';
                $keys['settings'] = $changedKeys;
            }
        }

        return ['categories' => $categories, 'keys' => $keys];
    }

    /**
     * @return array{modules: array, roles: array, settings: array}
     */
    protected function registry(): array
    {
        return [
            'modules' => config('tenant-defaults.modules', []),
            'roles' => config('tenant-defaults.roles', []),
            'settings' => config('tenant-defaults.settings', []),
        ];
    }

    protected function brandingRow(Tenant $tenant): TenantBranding
    {
        // Always query (never trust a cached null relation): after a previous
        // ensureDefaults() the parent may still hold "branding = null" in its
        // relation cache, which would make us insert a duplicate row.
        $row = $tenant->branding()->first();

        if ($row) {
            $tenant->setRelation('branding', $row);

            return $row;
        }

        // Legacy tenants stored branding inside `settings.branding`; backfill
        // it so the dedicated table becomes the single source of truth.
        $legacy = is_array($tenant->settings['branding'] ?? null) ? $tenant->settings['branding'] : [];

        $row = $tenant->branding()->create([
            'logo' => $legacy['logo'] ?? null,
            'favicon' => $legacy['favicon'] ?? null,
            'primary_color' => $legacy['primary_color'] ?? null,
            'secondary_color' => $legacy['secondary_color'] ?? null,
            'accent_color' => $legacy['accent_color'] ?? null,
            'font_family' => $legacy['font_family'] ?? null,
        ]);

        $tenant->setRelation('branding', $row);

        return $row;
    }

    protected function syncModuleRows(Tenant $tenant): void
    {
        $existing = $tenant->modules()->pluck('module');

        foreach (array_keys($this->registry()['modules']) as $key) {
            if (! $existing->contains($key)) {
                TenantModule::query()->create([
                    'tenant_id' => $tenant->id,
                    'module' => $key,
                    'enabled' => (bool) $this->registry()['modules'][$key]['default_enabled'],
                ]);
            }
        }
    }

    protected function syncRoleRows(Tenant $tenant): void
    {
        $existing = $tenant->roleConfiguration()->pluck('role');

        foreach (array_keys($this->registry()['roles']) as $role) {
            if (! $existing->contains($role)) {
                TenantRoleConfiguration::query()->create([
                    'tenant_id' => $tenant->id,
                    'role' => $role,
                    'enabled' => true,
                ]);
            }
        }
    }

    /**
     * Assert a list of candidate role keys contains only valid tenant roles.
     *
     * @param  array<array-key, mixed>  $roles
     */
    public function assertValidTenantRoles(array $roles): void
    {
        $valid = Role::values();

        foreach (array_keys($roles) as $role) {
            if (! is_string($role) || ! in_array($role, $valid, true)) {
                throw ValidationException::withMessages([
                    'roles' => ['Platform roles are not valid tenant roles.'],
                ]);
            }
        }
    }
}
