<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = Organization::query()
            ->withCount(['branches', 'users'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 12));

        return OrganizationResource::collection($organizations);
    }

    public function publicHospitals(Request $request): AnonymousResourceCollection
    {
        $organizations = Organization::query()
            ->where('status', 'active')
            ->whereIn('type', ['hospital', 'clinic'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return OrganizationResource::collection($organizations);
    }

    public function store(Request $request, AuditService $audit): OrganizationResource
    {
        $data = $request->validate([
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.type' => ['required', 'in:clinic,hospital,lab,pharmacy'],
            'organization.plan' => ['required', 'in:starter,growth,enterprise'],
            'organization.currency' => ['sometimes', 'string', 'size:3'],
            'admin.name' => ['required', 'string', 'max:255'],
            'admin.email' => ['required', 'email', 'unique:users,email'],
            'admin.password' => ['required', 'string', 'min:8'],
        ]);

        $organization = DB::transaction(function () use ($data) {
            $organization = Organization::create([
                'name' => $data['organization']['name'],
                'slug' => Str::slug($data['organization']['name']).'-'.Str::lower(Str::random(5)),
                'type' => $data['organization']['type'],
                'plan' => $data['organization']['plan'],
                'currency' => $data['organization']['currency'] ?? 'USD',
                'settings' => ['locale' => 'en', 'timezone' => 'UTC'],
            ]);

            $branch = Branch::create([
                'organization_id' => $organization->id,
                'name' => 'Main Branch',
            ]);

            User::create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'name' => $data['admin']['name'],
                'email' => $data['admin']['email'],
                'password' => Hash::make($data['admin']['password']),
                'role' => 'admin',
            ]);

            return $organization;
        });

        $audit->record($request, 'organization.created', $organization);

        return new OrganizationResource($organization->load('branches')->loadCount(['branches', 'users']));
    }

    public function show(Request $request): OrganizationResource
    {
        $organization = $request->user()->organization;
        abort_unless($organization, 404, 'No organization is attached to this account.');

        return new OrganizationResource($organization->load('branches'));
    }
}
