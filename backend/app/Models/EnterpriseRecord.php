<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'patient_id', 'doctor_id', 'type', 'title', 'encrypted_body', 'metadata'])]
class EnterpriseRecord extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'ehr_entries';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }
}
