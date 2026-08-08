<?php

namespace App\Models;

use App\Enums\HospitalApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A hospital onboarding application, stored on the control plane.
 *
 * Public submissions land here as `pending`; platform administrators review,
 * approve or reject them. Approval provisions the tenant through
 * App\Services\TenantProvisioner and links the resulting tenant.
 */
#[Fillable(['hospital_name', 'slug', 'type', 'contact_name', 'contact_email', 'contact_phone', 'description', 'status', 'reviewer_id', 'review_notes', 'reviewed_at', 'tenant_id'])]
class HospitalApplication extends Model
{
    protected $connection = 'control';

    protected $table = 'hospital_applications';

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'reviewer_id');
    }

    public function latestInvitation(): HasOne
    {
        return $this->hasOne(HospitalAdminInvitation::class, 'application_id')->latestOfMany();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            HospitalApplicationStatus::Approved->value,
            HospitalApplicationStatus::Rejected->value,
        ], true);
    }
}
