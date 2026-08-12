<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enabled module state for a hospital (control database).
 *
 * Required-ness is defined by the module registry (config/tenant-defaults.php)
 * and enforced by the configuration API, never stored per tenant.
 */
#[Fillable(['tenant_id', 'module', 'enabled'])]
class TenantModule extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_modules';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
