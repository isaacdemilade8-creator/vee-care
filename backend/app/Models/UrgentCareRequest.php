<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'branch_id',
    'patient_id',
    'assigned_to',
    'severity',
    'priority',
    'preferred_channel',
    'queue_name',
    'status',
    'symptoms',
    'message',
    'assigned_at',
    'resolved_at',
])]
class UrgentCareRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'symptoms' => 'array',
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
