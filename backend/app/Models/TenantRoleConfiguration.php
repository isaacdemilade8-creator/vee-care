<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enabled role state for a hospital (control database).
 *
 * Core roles (hospital_admin, doctor, nurse, patient) are required by the role
 * registry and can never be disabled. Optional roles (pharmacist,
 * lab_technician) can be toggled per hospital. Platform roles are never valid
 * tenant roles.
 */
#[Fillable(['tenant_id', 'role', 'enabled'])]
class TenantRoleConfiguration extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_role_configuration';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
