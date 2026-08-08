<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Control-plane audit record.
 *
 * Tracks hospital-onboarding lifecycle events (submitted, reviewed, approved,
 * rejected, invitation accepted). Writes go to the control database only and
 * never include passwords, tokens or database credentials.
 */
#[Fillable(['event', 'platform_user_id', 'application_id', 'tenant_id', 'metadata', 'ip_address', 'user_agent'])]
class PlatformAuditLog extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_audit_logs';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(HospitalApplication::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
