<?php

namespace App\Enums;

/**
 * Lifecycle states for a tenant (hospital) on the control plane.
 *
 * A tenant is only operational while `active`. Pending/provisioning tenants are
 * not yet ready; suspended/rejected/failed tenants are refused at the
 * ResolveTenant middleware boundary.
 */
enum TenantStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
