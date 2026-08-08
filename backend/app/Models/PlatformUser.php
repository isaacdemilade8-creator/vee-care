<?php

namespace App\Models;

/**
 * Platform / control-plane user.
 *
 * Represents a Vee-Care platform administrator (platform_super_admin /
 * platform_admin) stored in the control database. Tenant (hospital) users
 * continue to use the regular App\Models\User model against the resolved
 * tenant database.
 */
class PlatformUser extends User
{
    protected $connection = 'control';

    protected $table = 'users';
}
