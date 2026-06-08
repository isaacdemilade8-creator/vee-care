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
}
