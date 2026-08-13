<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'description', 'status'])]
class Department extends Model
{
    use BelongsToOrganization, HasFactory;

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }
}
