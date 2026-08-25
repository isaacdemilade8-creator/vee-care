<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable working-period definition owned by a hospital (Morning
 * 08:00-16:00, Night 22:00-06:00 ...). Shifts are hospital-level: departments
 * and wards schedule against them via duty assignments. An end time earlier
 * than the start time means the shift spans midnight.
 */
#[Fillable(['organization_id', 'name', 'start_time', 'end_time', 'status', 'description'])]
class Shift extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public function dutyAssignments(): HasMany
    {
        return $this->hasMany(DutyAssignment::class);
    }
}
