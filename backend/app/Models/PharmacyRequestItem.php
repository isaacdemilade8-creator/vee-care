<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pharmacy_request_id', 'medicine_id', 'medication_name', 'dosage', 'quantity', 'instructions', 'availability_status', 'pharmacist_note', 'dispense_status', 'dispensed_by', 'dispensed_at', 'given_by', 'given_at'])]
class PharmacyRequestItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'dispensed_at' => 'datetime',
            'given_at' => 'datetime',
        ];
    }

    public function pharmacyRequest(): BelongsTo
    {
        return $this->belongsTo(PharmacyRequest::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }
}
