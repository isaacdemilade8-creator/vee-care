<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the initial, tenant-local data required for a freshly provisioned
 * hospital database: one organization, one main branch and (optionally) the
 * hospital administrator.
 *
 * This intentionally mirrors the "hospital boundary" of a tenant database:
 * exactly one organization exists per tenant, so in-tenant records are linked
 * to it via the BelongsToOrganization trait while the database itself remains
 * the authoritative isolation boundary.
 */
class TenantSeeder extends Seeder
{
    public function run(Tenant $tenant, ?string $adminEmail = null, ?string $adminPassword = null): void
    {
        $organization = Organization::query()->create([
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'type' => $tenant->type,
            'plan' => $tenant->plan,
            'status' => 'active',
            'currency' => $tenant->currency,
            'settings' => $tenant->settings ?? ['locale' => 'en', 'timezone' => 'UTC'],
        ]);

        $branch = Branch::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Main Branch',
        ]);

        if ($adminEmail && $adminPassword) {
            User::query()->create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'name' => $tenant->name.' Administrator',
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'role' => Role::HospitalAdmin->value,
            ]);
        }
    }
}
