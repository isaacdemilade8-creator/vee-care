<?php

namespace App\Rules;

use App\Enums\HospitalApplicationStatus;
use App\Models\HospitalApplication;
use App\Models\Tenant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a requested hospital subdomain:
 *  - lowercase letters, numbers and hyphens (DNS label), 3-63 characters
 *  - not a reserved platform subdomain (api, admin, www, ...)
 *  - not already claimed by a tenant or a pending/active hospital application
 */
class AvailableHospitalSubdomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = strtolower(trim((string) $value));

        if ($slug === '') {
            $fail('The :attribute is required.');

            return;
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/', $slug)) {
            $fail('The :attribute must be 3-63 characters using only lowercase letters, numbers and hyphens, without leading or trailing hyphens.');

            return;
        }

        if (in_array($slug, config('tenancy.platform_subdomains', []), true)) {
            $fail('The :attribute is reserved and cannot be used.');

            return;
        }

        if (Tenant::query()->where('slug', $slug)->exists()) {
            $fail('The :attribute is already in use.');

            return;
        }

        $claimed = HospitalApplication::query()
            ->where('slug', $slug)
            ->whereNotIn('status', [HospitalApplicationStatus::Rejected->value])
            ->exists();

        if ($claimed) {
            $fail('The :attribute is already being applied for.');
        }
    }
}
