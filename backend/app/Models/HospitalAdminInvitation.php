<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring invitation for a tenant's hospital administrator.
 *
 * Issued on the control plane when a hospital application is approved. The
 * plaintext token is returned to the approver exactly once; only its SHA-256
 * digest is persisted, so a database leak never exposes a usable token.
 */
#[Fillable(['application_id', 'tenant_id', 'email', 'token_hash', 'expires_at', 'used_at'])]
class HospitalAdminInvitation extends Model
{
    protected $connection = 'control';

    protected $table = 'hospital_admin_invitations';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(HospitalApplication::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isRedeemable(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }
}
