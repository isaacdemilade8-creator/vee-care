<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'patient_id', 'recorded_by', 'temperature', 'heart_rate', 'blood_pressure', 'weight', 'height', 'recorded_at'])]
class Vital extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
