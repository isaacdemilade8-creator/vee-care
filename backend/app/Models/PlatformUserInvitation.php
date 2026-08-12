<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring invitation for a platform administrator.
 *
 * Issued on the control plane when a platform admin invites a colleague. The
 * plaintext token is returned to the inviter exactly once; only its SHA-256
 * digest is persisted, so a database leak never exposes a usable token. The
 * invited user row is created on the control plane only when the token is
 * redeemed.
 */
#[Fillable(['inviter_id', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at'])]
class PlatformUserInvitation extends Model
{
    protected $connection = 'control';

    protected $table = 'platform_user_invitations';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'inviter_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRedeemable(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
