<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform / control-plane user.
 *
 * Represents a Vee-Care platform administrator (platform_super_admin /
 * platform_admin) stored in the control database. Tenant (hospital) users
 * continue to use the regular App\Models\User model against the resolved
 * tenant database.
 *
 * `is_active` gates platform access: deactivated users are rejected by the
 * platform guard and their personal access tokens are revoked on
 * deactivation. The column exists only in the control database.
 */
class PlatformUser extends User
{
    protected $connection = 'control';

    protected $table = 'users';

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'is_active' => 'boolean',
        ];
    }

    /**
     * Invitations issued by this platform administrator.
     */
    public function issuedInvitations(): HasMany
    {
        return $this->hasMany(PlatformUserInvitation::class, 'inviter_id');
    }
}
