<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantDatabaseManager;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantDatabaseManager $databases,
    ) {
    }

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
            'subdomain' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/'],
        ]);

        if (array_key_exists('email', $data) !== array_key_exists('password', $data)) {
            return response()->json(['message' => 'Both email and password are required to create an administrator.'], 422);
        }

        $tenant = $this->provisioner->provision($data['name'], [
            'email' => $data['email'] ?? null,
            'password' => $data['password'] ?? null,
            'type' => $data['type'] ?? 'hospital',
            'plan' => $data['plan'] ?? 'starter',
            'currency' => $data['currency'] ?? 'USD',
            'slug' => $data['subdomain'] ?? null,
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
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended', 'failed'])],
            'settings' => ['sometimes', 'array'],
        ]);

        $tenant->update($data);

        return new TenantResource($tenant->load('domains'));
    }

    public function addDomain(Request $request, Tenant $tenant): TenantResource
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'unique:tenant_domains,domain'],
        ]);

        $tenant->domains()->create([
            'domain' => strtolower($data['domain']),
            'is_primary' => ! $tenant->domains()->exists(),
        ]);

        return new TenantResource($tenant->load('domains'));
    }

    public function removeDomain(Tenant $tenant, TenantDomain $domain): JsonResponse
    {
        abort_if($domain->tenant_id !== $tenant->id, 404, 'Domain not found.');

        if ($domain->is_primary) {
            return response()->json(['message' => 'The primary domain cannot be removed.'], 422);
        }

        $domain->delete();

        return response()->json(['message' => 'Domain removed.']);
    }

    public function migrate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => 'provisioning']);

        try {
            $this->databases->migrate($tenant);
            $tenant->update(['status' => 'active']);
        } catch (\Throwable $e) {
            $tenant->update(['status' => 'failed']);

            return response()->json(['message' => 'Migration failed: '.$e->getMessage()], 500);
        }

        return response()->json(['message' => 'Tenant migrations applied successfully.']);
    }
}
