<?php

namespace App\Http\Controllers\Api\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Rules\AvailableHospitalSubdomain;
use App\Services\TenantConfigurationService;
use App\Services\TenantDatabaseManager;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantDatabaseManager $databases,
        private readonly TenantConfigurationService $configuration,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()
            ->withCount('domains')
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return TenantResource::collection($tenants);
    }

    public function store(Request $request): TenantResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'type' => ['sometimes', 'in:clinic,hospital,lab,pharmacy'],
            'plan' => ['sometimes', 'in:starter,growth,enterprise'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'subdomain' => ['sometimes', 'nullable', new AvailableHospitalSubdomain],
        ]);

        if (array_key_exists('email', $data) !== array_key_exists('password', $data)) {
            return response()->json(['message' => 'Both email and password are required to create an administrator.'], 422);
        }

        try {
            $tenant = $this->provisioner->provision($data['name'], [
                'email' => $data['email'] ?? null,
                'password' => $data['password'] ?? null,
                'type' => $data['type'] ?? 'hospital',
                'plan' => $data['plan'] ?? 'starter',
                'currency' => $data['currency'] ?? 'USD',
                'slug' => $data['subdomain'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Never leave a half-created tenant (or an occupied subdomain)
            // behind when direct provisioning fails.
            $failed = Tenant::query()
                ->where('status', TenantStatus::Failed->value)
                ->where('name', $data['name'])
                ->latest()
                ->first();

            if ($failed) {
                $this->databases->dropDatabase($failed);
                $failed->delete();
            }

            throw $e;
        }

        $this->audit($request, 'tenant.created', $tenant, [
            'slug' => $tenant->slug,
            'name' => $tenant->name,
        ]);

        return new TenantResource($tenant->load('domains'));
    }

    public function show(Tenant $tenant): TenantResource
    {
        return new TenantResource($tenant->load('domains'));
    }

    public function update(Request $request, Tenant $tenant): TenantResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:clinic,hospital,lab,pharmacy'],
            'plan' => ['sometimes', 'in:starter,growth,enterprise'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', Rule::in(TenantStatus::values())],
            'settings' => ['sometimes', 'array'],
        ]);

        // General settings must respect the same strict allowlist as the two
        // configuration surfaces. validated() drops nested keys with no rule,
        // so re-attach the raw section before the allowlist check.
        if ($request->has('settings')) {
            $settingsRules = collect($this->configuration->rules())
                ->only(['settings', 'settings.locale', 'settings.timezone', 'settings.date_format', 'settings.time_format', 'settings.default_appointment_duration'])
                ->all();

            $data = array_merge($data, $request->validate($settingsRules));
            $data['settings'] = array_merge($request->input('settings', []), $data['settings'] ?? []);
            $this->configuration->assertKnownSettings($data);
        }

        $tenant->update($data);

        $this->audit($request, 'tenant.updated', $tenant, [
            'changed' => array_keys($data),
        ]);

        return new TenantResource($tenant->load('domains'));
    }

    public function addDomain(Request $request, Tenant $tenant): TenantResource
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'unique:tenant_domains,domain'],
        ]);

        $domain = $tenant->domains()->create([
            'domain' => strtolower($data['domain']),
            'is_primary' => ! $tenant->domains()->exists(),
        ]);

        $this->audit($request, 'tenant.domain_added', $tenant, [
            'domain' => $domain->domain,
        ]);

        return new TenantResource($tenant->load('domains'));
    }

    public function removeDomain(Request $request, Tenant $tenant, TenantDomain $domain): JsonResponse
    {
        abort_if($domain->tenant_id !== $tenant->id, 404, 'Domain not found.');

        if ($domain->is_primary) {
            return response()->json(['message' => 'The primary domain cannot be removed.'], 422);
        }

        $domain->delete();

        $this->audit($request, 'tenant.domain_removed', $tenant, [
            'domain' => $domain->domain,
        ]);

        return response()->json(['message' => 'Domain removed.']);
    }

    public function migrate(Request $request, Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => TenantStatus::Provisioning->value]);

        try {
            $this->databases->migrate($tenant);
            $tenant->update(['status' => TenantStatus::Active->value]);
        } catch (\Throwable $e) {
            $tenant->update(['status' => TenantStatus::Failed->value]);

            // Never record raw exception text in the audit log or API response:
            // a DB exception may embed hostnames/credentials. Log the exception
            // class only; the full error stays in the server log for support.
            $this->audit($request, 'tenant.migrate_failed', $tenant, [
                'error' => $e::class,
            ]);

            return response()->json(['message' => 'Tenant migration failed.'], 500);
        }

        $this->audit($request, 'tenant.migrated', $tenant, [
            'result' => 'ok',
        ]);

        return response()->json(['message' => 'Tenant migrations applied successfully.']);
    }

    /**
     * Record a control-plane audit event. Metadata contains only changed keys
     * and non-sensitive identity — never values that could be secrets.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(Request $request, string $event, Tenant $tenant, array $metadata = []): PlatformAuditLog
    {
        return PlatformAuditLog::query()->create([
            'event' => $event,
            'platform_user_id' => $request->user()?->id,
            'tenant_id' => $tenant->id,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
