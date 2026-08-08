<?php

namespace App\Enums;

/**
 * Tenant (hospital) roles.
 *
 * Roles are stored as a string on the `users.role` column inside each tenant
 * database. The highest tenant-level privilege is `hospital_admin`. Platform
 * roles (platform_super_admin / platform_admin) live exclusively in the control
 * database and are defined in App\Enums\PlatformRole.
 */
enum Role: string
{
    case HospitalAdmin = 'hospital_admin';
    case Doctor = 'doctor';
    case Nurse = 'nurse';
    case Patient = 'patient';
    case LabTechnician = 'lab_technician';
    case Pharmacist = 'pharmacist';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Roles a hospital administrator may assign to tenant users. */
    public static function assignableByAdmin(): array
    {
        return [
            self::Doctor->value,
            self::Nurse->value,
            self::Patient->value,
            self::LabTechnician->value,
            self::Pharmacist->value,
        ];
    }

    /** Roles that represent hospital staff (non-patient, non-admin). */
    public static function staff(): array
    {
        return [
            self::Doctor->value,
            self::Nurse->value,
            self::LabTechnician->value,
            self::Pharmacist->value,
        ];
    }
}
