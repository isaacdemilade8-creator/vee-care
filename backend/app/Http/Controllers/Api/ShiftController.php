<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftResource;
use App\Models\DutyAssignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shift definition management.
 *
 * Runs inside the tenant database (ResolveTenant switches the connection before
 * these routes execute), so route-model-bound shifts can only ever resolve to a
 * record of the request's own hospital: another tenant's id yields a 404. The
 * route group adds `role:hospital_admin`, so only hospital administrators can
 * reach any of these endpoints.
 *
 * A shift is a reusable working-period definition (Morning 08:00-16:00, Night
 * 22:00-06:00 ...). An end time earlier than the start time is a valid
 * midnight-crossing shift. Deactivating a shift (`status` = inactive) is the
 * supported lifecycle: existing duty assignments remain intact while new
 * assignments are rejected by the service. Deletion is blocked while any duty
 * assignment references the shift so history is never silently destroyed.
 */
class ShiftController extends Controller
{
    protected const STATUSES = ['active', 'inactive'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Shift::query()
            ->withCount('dutyAssignments')
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->string('search')->toString(), fn ($q, string $search) => $q->where('name', 'like', "%{$search}%"))
            ->latest();

        return ShiftResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function store(Request $request, AuditService $audit): ShiftResource
    {
        $data = $this->validateShift($request);
        $this->assertValidWindow($data['start_time'], $data['end_time']);

        $shift = Shift::create(array_merge($data, [
            'status' => $data['status'] ?? Shift::STATUS_ACTIVE,
        ]));

        $audit->record($request, 'shift.created', $shift, ['name' => $shift->name]);

        return new ShiftResource($shift);
    }

    public function show(Shift $shift): ShiftResource
    {
        return new ShiftResource($shift->loadCount('dutyAssignments'));
    }

    public function update(Request $request, Shift $shift, AuditService $audit): ShiftResource
    {
        $data = $this->validateShift($request, $shift);

        $this->assertValidWindow(
            $data['start_time'] ?? $shift->start_time,
            $data['end_time'] ?? $shift->end_time,
        );

        $wasActive = $shift->status === Shift::STATUS_ACTIVE;
        $shift->update($data);

        $audit->record($request, 'shift.updated', $shift, ['changed' => array_keys($data)]);

        if ($wasActive && $shift->status === Shift::STATUS_INACTIVE) {
            $audit->record($request, 'shift.deactivated', $shift, ['name' => $shift->name]);
        }

        return new ShiftResource($shift->loadCount('dutyAssignments'));
    }

    public function destroy(Request $request, Shift $shift, AuditService $audit): JsonResponse
    {
        $hasDuties = DutyAssignment::query()
            ->where('shift_id', $shift->id)
            ->exists();

        if ($hasDuties) {
            throw ValidationException::withMessages([
                'shift_id' => ['This shift has duty assignments and cannot be deleted. Deactivate it instead.'],
            ]);
        }

        $shift->delete();

        $audit->record($request, 'shift.deleted', $shift, ['name' => $shift->name]);

        return response()->json(['message' => 'Shift deleted.']);
    }

    protected function validateShift(Request $request, ?Shift $shift = null): array
    {
        $unique = Rule::unique('shifts', 'name')
            ->where('organization_id', $this->organizationId($request))
            ->whereNull('deleted_at')
            ->ignore($shift?->id);

        $required = $shift ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100', $unique],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * A shift must cover a positive duration. start == end is rejected because
     * a 24-hour shift is ambiguous under the midnight-crossing convention
     * (end earlier than start means "spans midnight"); an end time earlier
     * than the start time is valid and not treated as negative.
     */
    protected function assertValidWindow(string $start, string $end): void
    {
        if ($start === $end) {
            throw ValidationException::withMessages([
                'end_time' => ['The shift must have a positive duration. Start and end times must be different.'],
            ]);
        }
    }

    protected function organizationId(Request $request): int
    {
        return $request->user()->organization_id
            ? (int) $request->user()->organization_id
            : (int) Organization::query()->value('id');
    }
}
