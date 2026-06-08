<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'medicine_id', 'patient_id', 'quantity', 'status', 'pickup_code', 'notes', 'pharmacist_note', 'prepared_by', 'prepared_at', 'picked_up_at'])]
class MedicineOrder extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'prepared_at' => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
