<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring invitation for a tenant (hospital) user.
 *
 * Issued by a hospital administrator when inviting staff, patients or
 * optional-role users. The plaintext token is returned to the inviter exactly
 * once; only its SHA-256 digest is persisted, so a database leak never exposes
 * a usable token. The invited user row is created in the tenant database only
 * when the token is redeemed, and the invitation itself is stored in the tenant
 * database so it is naturally scoped to one hospital.
 */
#[Fillable(['inviter_id', 'email', 'name', 'role', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'])]
class UserInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isRedeemable(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired();
    }
}
