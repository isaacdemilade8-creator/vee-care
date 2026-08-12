<?php

use App\Enums\Role;

/*
|--------------------------------------------------------------------------
| Tenant configuration defaults & registries
|--------------------------------------------------------------------------
|
| The single authoritative representation of the Vee-Care tenant
| configuration model: supported fonts, default branding (inherited when a
| hospital has not customized a field), the module registry, the role
| registry and the allowed general-settings schema.
|
| Everything here is consumed by TenantConfigurationService so backend
| controllers, seeders and the provisioning path never duplicate defaults.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Supported fonts
    |--------------------------------------------------------------------------
    |
    | Branding font-family is restricted to this allowlist. The frontend's
    | design system loads these families; anything else is rejected. "system"
    | maps to the platform/system UI font stack.
    |
    */

    'fonts' => ['Inter', 'Roboto', 'Open Sans', 'Poppins', 'Montserrat', 'system'],

    /*
    |--------------------------------------------------------------------------
    | Default branding
    |--------------------------------------------------------------------------
    |
    | These are the Vee-Care defaults a hospital inherits when it has not
    | customized a branding field. A tenant_branding row stores null for any
    | field the hospital has not overridden, and the frontend applies these
    | defaults so a fresh hospital "just works" with the Vee-Care brand.
    |
    */

    'branding' => [
        'primary_color' => '#0f766e',
        'secondary_color' => '#0f766e',
        'accent_color' => '#0f766e',
        'font_family' => 'Inter',
    ],

    /*
    |--------------------------------------------------------------------------
    | General settings
    |--------------------------------------------------------------------------
    |
    | Strict allowlist of supported general settings (stored in the tenants
    | `settings` JSON column). Unknown keys are rejected; the allowed keys are
    | the only ones ever surfaced to the platform.
    |
    */

    'settings' => [
        'defaults' => [
            'locale' => 'en',
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'default_appointment_duration' => 30,
        ],
        'locales' => ['en'],
        'timezones' => ['UTC', 'Africa/Lagos', 'America/New_York', 'Europe/London', 'Asia/Tokyo', 'Australia/Sydney'],
        'date_formats' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y'],
        'time_formats' => ['H:i', 'h:i A'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module registry
    |--------------------------------------------------------------------------
    |
    | Every Vee-Care capability a hospital can enable or disable. `required`
    | modules are always enabled and cannot be disabled through a
    | configuration request; `default_enabled` seeds the initial state of
    | optional modules for newly provisioned hospitals.
    |
    */

    'modules' => [
        'appointments' => [
            'name' => 'Appointments',
            'description' => 'Booking and managing appointments.',
            'default_enabled' => true,
            'required' => true,
        ],
        'ehr' => [
            'name' => 'Electronic Health Records',
            'description' => 'Medical records, notes and documents.',
            'default_enabled' => true,
            'required' => true,
        ],
        'prescriptions' => [
            'name' => 'Prescriptions',
            'description' => 'Issuing and tracking prescriptions.',
            'default_enabled' => true,
            'required' => false,
        ],
        'messaging' => [
            'name' => 'Messaging',
            'description' => 'Secure in-app messaging between staff and patients.',
            'default_enabled' => true,
            'required' => true,
        ],
        'laboratory' => [
            'name' => 'Laboratory',
            'description' => 'Lab tests, orders and results.',
            'default_enabled' => true,
            'required' => false,
        ],
        'pharmacy' => [
            'name' => 'Pharmacy',
            'description' => 'Medicine inventory, orders and dispensing.',
            'default_enabled' => true,
            'required' => false,
        ],
        'telemedicine' => [
            'name' => 'Telemedicine',
            'description' => 'Video consultations with practitioners.',
            'default_enabled' => true,
            'required' => false,
        ],
        'urgent_care' => [
            'name' => 'Urgent Care',
            'description' => 'Urgent-care requests and triage.',
            'default_enabled' => true,
            'required' => false,
        ],
        'patient_portal' => [
            'name' => 'Patient Portal',
            'description' => 'Patient cards and self-service access.',
            'default_enabled' => true,
            'required' => true,
        ],
        'nurse_station' => [
            'name' => 'Nurse Station',
            'description' => 'Nursing workflow and station management.',
            'default_enabled' => true,
            'required' => false,
        ],
        'blog' => [
            'name' => 'Blog & Community',
            'description' => 'Public blog and community posts.',
            'default_enabled' => true,
            'required' => false,
        ],
        'enterprise' => [
            'name' => 'Enterprise Analytics',
            'description' => 'Dashboards and administrative analytics.',
            'default_enabled' => true,
            'required' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role configuration
    |--------------------------------------------------------------------------
    |
    | Which tenant roles exist and whether each is foundational. Core roles
    | (hospital_admin, doctor, nurse, patient) are required and always enabled;
    | optional roles (pharmacist, lab_technician) can be disabled per hospital.
    | Platform roles never appear here — they belong exclusively to the control
    | plane and are rejected by tenant-role validation.
    |
    */

    'roles' => [
        Role::HospitalAdmin->value => ['label' => 'Hospital Admin', 'required' => true],
        Role::Doctor->value => ['label' => 'Doctor', 'required' => true],
        Role::Nurse->value => ['label' => 'Nurse', 'required' => true],
        Role::Patient->value => ['label' => 'Patient', 'required' => true],
        Role::Pharmacist->value => ['label' => 'Pharmacist', 'required' => false],
        Role::LabTechnician->value => ['label' => 'Lab Technician', 'required' => false],
    ],
];
