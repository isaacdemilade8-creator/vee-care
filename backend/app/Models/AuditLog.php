<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'metadata', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
