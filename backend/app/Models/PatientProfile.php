<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'branch_id', 'user_id', 'patient_number', 'allergies', 'chronic_conditions', 'emergency_contact', 'encrypted_summary'])]
class PatientProfile extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'allergies' => 'array',
            'chronic_conditions' => 'array',
            'emergency_contact' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ensure a tenant user who holds the patient role has a patient profile.
     * Idempotent; used by registration and by the invitation-acceptance flow.
     */
    public static function ensureFor(User $user): ?PatientProfile
    {
        if (! $user->isRole('patient')) {
            return null;
        }

        return self::firstOrCreate(
            ['user_id' => $user->id],
            [
                'organization_id' => $user->organization_id,
                'branch_id' => $user->branch_id,
                'patient_number' => 'PAT-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'allergies' => [],
                'chronic_conditions' => [],
            ],
        );
    }
}
