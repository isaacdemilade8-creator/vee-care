<?php

namespace App\Enums;

/**
 * Platform (control-plane) roles.
 *
 * These roles exist only in the control database on App\Models\PlatformUser.
 * Tenant databases never contain platform roles; the tenant equivalent of a
 * platform administrator is App\Enums\Role::HospitalAdmin.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'platform_super_admin';
    case Admin = 'platform_admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
