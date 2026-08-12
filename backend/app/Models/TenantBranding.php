<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hospital branding configuration (control database).
 *
 * A null field means "inherit the Vee-Care default". Values are validated by
 * the configuration API before they reach this table.
 */
#[Fillable(['tenant_id', 'logo', 'favicon', 'primary_color', 'secondary_color', 'accent_color', 'font_family'])]
class TenantBranding extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_branding';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
