<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

#[Fillable(['organization_id', 'room_id', 'bed_number', 'status', 'is_active'])]
class Bed extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function ward(): HasOneThrough
    {
        return $this->hasOneThrough(Ward::class, Room::class, 'id', 'id', 'room_id', 'ward_id');
    }
}
