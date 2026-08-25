<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Department;
use App\Models\DutyAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Practitioner duty assignment workflow.
 *
 * SHIFT defines a working period; a DUTY ASSIGNMENT records who is working it
 * on a particular date. All writes run inside a database transaction and the
 * affected practitioner's user row is locked (`lockForUpdate`) while the
 * candidate window is verified, so two concurrent admins can never double-book
 * the same practitioner with overlapping shifts. Overlap itself cannot be
 * expressed as a portable database exclusion constraint (MySQL has no
 * exclusion constraints), so the application lock is the authoritative guard.
 *
 * Overlap is computed on absolute datetime windows: a shift whose end time is
 * earlier than its start time spans midnight, so 22:00 -> 06:00 on 2026-08-20
 * occupies [2026-08-20 22:00, 2026-08-21 06:00) and correctly conflicts with a
 * 04:00 -> 12:00 shift on 2026-08-21 while remaining compatible with the same
 * shift on 2026-08-20. Comparisons use the tenant-configured timezone so the
 * wall-clock hospital day is authoritative.
 *
 * "Currently on duty" is never stored. It is derived from `duty_date`, the
 * shift window and `status` via the same absolute-window math.
 */
class DutyService
{
    /**
     * Resolve the hospital's configured timezone (defaults to UTC). The shift
     * and duty times are stored as hospital-local wall clock; only the
     * "now" boundary of the current-duty lookup needs a timezone.
     */
    public function timezone(): string
    {
        $tenant = app(TenantResolver::class)->current();

        if (! $tenant) {
            return 'UTC';
        }

        $settings = app(TenantConfigurationService::class)->settings($tenant);

        return $settings['timezone'] ?? 'UTC';
    }

    /**
     * Assign a practitioner to a shift on a date. Serialised per practitioner
     * via the user row lock; the overlap check runs under that lock so a
     * concurrent assignment can never slip through.
     */
    public function assign(array $data): DutyAssignment
    {
        $timezone = $this->timezone();

        return DB::transaction(function () use ($data, $timezone): DutyAssignment {
            $practitioner = $this->lockPractitioner((int) $data['practitioner_id']);
            $this->assertPractitioner($practitioner);

            $shift = Shift::findOrFail($data['shift_id']);
            $this->assertShiftAssignable($shift);

            $this->assertDepartmentWard($data['department_id'], $data['ward_id'] ?? null);

            $this->assertNoOverlap(
                $practitioner->id,
                $data['duty_date'],
                $shift->start_time,
                $shift->end_time,
                null,
                $timezone,
            );

            return DutyAssignment::create([
                'organization_id' => null,
                'practitioner_id' => $practitioner->id,
                'shift_id' => $shift->id,
                'department_id' => $data['department_id'],
                'ward_id' => $data['ward_id'] ?? null,
                'duty_date' => $data['duty_date'],
                'status' => DutyAssignment::STATUS_SCHEDULED,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Change an assignment. Core fields (practitioner/shift/department/ward/
     * date) may only change while the duty is still `scheduled`; a completed or
     * cancelled duty keeps its record for history. Status transitions:
     * scheduled -> cancelled at any time, scheduled -> completed once the
     * window has fully ended. Changing the schedule re-runs the overlap check
     * under the (possibly new) practitioner's lock.
     */
    public function update(DutyAssignment $duty, array $data): DutyAssignment
    {
        $timezone = $this->timezone();

        return DB::transaction(function () use ($duty, $data, $timezone): DutyAssignment {
            /** @var DutyAssignment $duty */
            $duty = DutyAssignment::query()
                ->whereKey($duty->id)
                ->lockForUpdate()
                ->firstOrFail();

            $practitioner = $this->lockPractitioner((int) ($data['practitioner_id'] ?? $duty->practitioner_id));
            $this->assertPractitioner($practitioner);

            $coreChanged = array_intersect(
                array_keys($data),
                ['practitioner_id', 'shift_id', 'department_id', 'ward_id', 'duty_date'],
            );

            if ($duty->status !== DutyAssignment::STATUS_SCHEDULED && $coreChanged) {
                throw ValidationException::withMessages([
                    'status' => ['Only scheduled duties can be reassigned or moved.'],
                ]);
            }

            $newStatus = $data['status'] ?? $duty->status;

            if ($duty->status !== DutyAssignment::STATUS_SCHEDULED && $newStatus !== $duty->status) {
                throw ValidationException::withMessages([
                    'status' => ['A completed or cancelled duty cannot change status.'],
                ]);
            }

            if ($newStatus === DutyAssignment::STATUS_COMPLETED) {
                $end = $this->windowEnd($data, $duty, $timezone);

                if (Carbon::now($timezone)->lt($end)) {
                    throw ValidationException::withMessages([
                        'status' => ['A duty whose shift has not ended cannot be marked completed.'],
                    ]);
                }
            }

            foreach (['practitioner_id', 'shift_id', 'department_id', 'ward_id', 'duty_date', 'status', 'notes'] as $key) {
                if (array_key_exists($key, $data)) {
                    $duty->{$key} = $data[$key];
                }
            }

            if ($coreChanged) {
                $shift = Shift::findOrFail($duty->shift_id);
                $this->assertShiftAssignable($shift);
                $this->assertDepartmentWard($duty->department_id, $duty->ward_id);
                $this->assertNoOverlap(
                    $duty->practitioner_id,
                    $duty->duty_date,
                    $shift->start_time,
                    $shift->end_time,
                    $duty->id,
                    $timezone,
                );
            }

            $duty->save();

            return $duty;
        });
    }

    /**
     * Hard delete for administrative corrections. Only `scheduled` duties
     * whose window has not started may be deleted; anything already worked or
     * cancelled is kept (cancel it or mark it completed instead).
     */
    public function remove(DutyAssignment $duty): void
    {
        DB::transaction(function () use ($duty): void {
            /** @var DutyAssignment $duty */
            $duty = DutyAssignment::query()
                ->whereKey($duty->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($duty->status !== DutyAssignment::STATUS_SCHEDULED) {
                throw ValidationException::withMessages([
                    'status' => ['Only scheduled duties can be deleted.'],
                ]);
            }

            $shift = Shift::find($duty->shift_id);

            if ($shift) {
                $timezone = $this->timezone();
                [, $end] = static::window($duty->duty_date, $shift->start_time, $shift->end_time, $timezone);

                if (Carbon::now($timezone)->gte($end)) {
                    throw ValidationException::withMessages([
                        'status' => ['This duty has already started or ended and cannot be deleted. Cancel it instead.'],
                    ]);
                }
            }

            $duty->delete();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Absolute window math
    |--------------------------------------------------------------------------
    */

    /**
     * Absolute [start, end) Carbon window for a duty dated `$date` running
     * shift `$start` -> `$end`, in the given timezone. An end earlier than the
     * start spans midnight and rolls over into the next day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(string $date, string $start, string $end, string $timezone = 'UTC'): array
    {
        $startMinutes = static::toMinutes($start);
        $endMinutes = static::toMinutes($end);
        $duration = $endMinutes > $startMinutes
            ? $endMinutes - $startMinutes
            : $endMinutes - $startMinutes + 1440;

        $startCarbon = Carbon::createFromFormat('Y-m-d H:i', $date.' '.static::fromMinutes($startMinutes), $timezone);
        $endCarbon = $startCarbon->copy()->addMinutes($duration);

        return [$startCarbon, $endCarbon];
    }

    /**
     * Whether two dated shift windows overlap. Midnight-crossing shifts are
     * normalised into absolute windows first.
     */
    public static function overlaps(
        string $dateA,
        string $startA,
        string $endA,
        string $dateB,
        string $startB,
        string $endB,
        string $timezone = 'UTC',
    ): bool {
        [$startA, $endA] = static::window($dateA, $startA, $endA, $timezone);
        [$startB, $endB] = static::window($dateB, $startB, $endB, $timezone);

        return $startA->lt($endB) && $startB->lt($endA);
    }

    /*
    |--------------------------------------------------------------------------
    | Guards (run under the practitioner row lock)
    |--------------------------------------------------------------------------
    */

    protected function lockPractitioner(int $practitionerId): User
    {
        return User::query()
            ->whereKey($practitionerId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function assertPractitioner(User $practitioner): void
    {
        if (! $practitioner->isRole(...Role::staff())) {
            throw ValidationException::withMessages([
                'practitioner_id' => ['The selected user is not an active staff member.'],
            ]);
        }

        if (! $practitioner->is_active) {
            throw ValidationException::withMessages([
                'practitioner_id' => ['The selected practitioner is inactive.'],
            ]);
        }
    }

    protected function assertShiftAssignable(Shift $shift): void
    {
        if ($shift->status !== Shift::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'shift_id' => ['This shift is deactivated and cannot receive new assignments.'],
            ]);
        }
    }

    protected function assertDepartmentWard(int $departmentId, ?int $wardId): void
    {
        $department = Department::find($departmentId);

        if (! $department || $department->status !== 'active') {
            throw ValidationException::withMessages([
                'department_id' => ['The selected department is not active.'],
            ]);
        }

        if ($wardId === null) {
            return;
        }

        $ward = Ward::find($wardId);

        if (! $ward || $ward->department_id !== $departmentId) {
            throw ValidationException::withMessages([
                'ward_id' => ['The selected ward does not belong to the selected department.'],
            ]);
        }

        if ($ward->status !== 'active') {
            throw ValidationException::withMessages([
                'ward_id' => ['The selected ward is not active.'],
            ]);
        }
    }

    /**
     * Reject if the practitioner already has a non-cancelled duty whose window
     * intersects the candidate window. Only duties dated within one day of the
     * candidate date can overlap it (a night shift extends at most one day), so
     * the lookup stays small and index-backed.
     */
    protected function assertNoOverlap(
        int $practitionerId,
        string $dutyDate,
        string $start,
        string $end,
        ?int $exceptId,
        string $timezone,
    ): void {
        $candidate = Carbon::parse($dutyDate);
        $from = $candidate->copy()->subDay()->toDateString();
        $to = $candidate->copy()->addDay()->toDateString();

        $existing = DutyAssignment::query()
            ->where('practitioner_id', $practitionerId)
            ->whereBetween('duty_date', [$from, $to])
            ->where('status', '!=', DutyAssignment::STATUS_CANCELLED)
            ->when($exceptId, fn ($query, int $id) => $query->where('id', '!=', $id))
            ->with('shift:id,start_time,end_time')
            ->get();

        foreach ($existing as $duty) {
            if (static::overlaps($dutyDate, $start, $end, $duty->duty_date, $duty->shift->start_time, $duty->shift->end_time, $timezone)) {
                throw ValidationException::withMessages([
                    'shift_id' => ['This practitioner already has an overlapping duty on '.$duty->duty_date.'.'],
                ]);
            }
        }
    }

    protected function windowEnd(array $data, DutyAssignment $duty, string $timezone): Carbon
    {
        $shiftId = $data['shift_id'] ?? $duty->shift_id;
        $date = $data['duty_date'] ?? $duty->duty_date;
        $shift = Shift::findOrFail($shiftId);

        [, $end] = static::window($date, $shift->start_time, $shift->end_time, $timezone);

        return $end;
    }

    protected static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    protected static function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
