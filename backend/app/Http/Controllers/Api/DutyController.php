<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DutyAssignmentResource;
use App\Models\DutyAssignment;
use App\Services\AuditService;
use App\Services\DutyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Duty assignment management and live "who is on duty" lookup.
 *
 * Runs inside the tenant database (ResolveTenant switches the connection before
 * these routes execute), so route-model-bound duties can only ever resolve to a
 * record of the request's own hospital: another tenant's id yields a 404. The
 * route group adds `role:hospital_admin`, so only hospital administrators can
 * reach any of these endpoints.
 *
 * All writes delegate to DutyService, which serialises assignments per
 * practitioner (row lock) and enforces the overlap rules on absolute datetime
 * windows. `current` answers "who is on duty right now?" with a date-bounded,
 * index-backed query evaluated in the hospital's configured timezone — it never
 * scans duty history.
 */
class DutyController extends Controller
{
    protected const STATUSES = ['scheduled', 'completed', 'cancelled'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DutyAssignment::query()
            ->with(['practitioner', 'shift', 'department', 'ward'])
            ->when($request->string('practitioner_id')->toString(), fn ($q, string $id) => $q->where('practitioner_id', $id))
            ->when($request->string('shift_id')->toString(), fn ($q, string $id) => $q->where('shift_id', $id))
            ->when($request->string('department_id')->toString(), fn ($q, string $id) => $q->where('department_id', $id))
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q->where('ward_id', $id))
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->string('date')->toString(), fn ($q, string $date) => $q->whereDate('duty_date', $date))
            ->when($request->string('from')->toString(), fn ($q, string $date) => $q->whereDate('duty_date', '>=', $date))
            ->when($request->string('to')->toString(), fn ($q, string $date) => $q->whereDate('duty_date', '<=', $date))
            ->when($request->string('search')->toString(), function ($q, string $search): void {
                $q->whereHas('practitioner', fn ($practitioner) => $practitioner->where('name', 'like', "%{$search}%"));
            })
            ->orderByDesc('duty_date')
            ->orderByDesc('id');

        return DutyAssignmentResource::collection($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request, DutyService $service, AuditService $audit): DutyAssignmentResource
    {
        $data = $this->validateStore($request);

        $duty = $service->assign($data);
        $duty->load(['practitioner', 'shift', 'department', 'ward']);

        $audit->record($request, 'duty.created', $duty, [
            'practitioner_id' => $duty->practitioner_id,
            'shift_id' => $duty->shift_id,
            'duty_date' => $duty->duty_date,
        ]);

        return new DutyAssignmentResource($duty);
    }

    public function show(DutyAssignment $duty): DutyAssignmentResource
    {
        return new DutyAssignmentResource($duty->load(['practitioner', 'shift', 'department', 'ward']));
    }

    public function update(Request $request, DutyAssignment $duty, DutyService $service, AuditService $audit): DutyAssignmentResource
    {
        $data = $this->validateUpdate($request);

        $duty = $service->update($duty, $data);
        $duty->load(['practitioner', 'shift', 'department', 'ward']);

        $audit->record($request, 'duty.updated', $duty, ['changed' => array_keys($data)]);

        if (isset($data['status']) && $data['status'] === DutyAssignment::STATUS_CANCELLED) {
            $audit->record($request, 'duty.cancelled', $duty, ['practitioner_id' => $duty->practitioner_id]);
        }

        return new DutyAssignmentResource($duty);
    }

    public function destroy(Request $request, DutyAssignment $duty, DutyService $service, AuditService $audit): JsonResponse
    {
        $service->remove($duty);

        $audit->record($request, 'duty.deleted', $duty, ['practitioner_id' => $duty->practitioner_id]);

        return response()->json(['message' => 'Duty assignment deleted.']);
    }

    /**
     * Practitioners currently on duty. Duties are narrowed in SQL to
     * `scheduled` rows dated today or yesterday (a night shift dated yesterday
     * extends into today) and then window-checked in the hospital's timezone —
     * historical assignments are never loaded. Grouped by department / ward /
     * shift so a ward's full team (doctor + nurses + pharmacist) renders as one
     * unit.
     */
    public function current(Request $request, DutyService $service): JsonResponse
    {
        $timezone = $service->timezone();
        $now = Carbon::now($timezone);

        $duties = DutyAssignment::query()
            ->with(['practitioner', 'shift', 'department', 'ward'])
            ->where('status', DutyAssignment::STATUS_SCHEDULED)
            ->whereBetween('duty_date', [
                $now->copy()->subDay()->toDateString(),
                $now->toDateString(),
            ])
            ->when($request->string('department_id')->toString(), fn ($q, string $id) => $q->where('department_id', $id))
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q->where('ward_id', $id))
            ->get();

        $groups = [];

        foreach ($duties as $duty) {
            if (! $duty->practitioner || ! $duty->shift) {
                continue;
            }

            [$start, $end] = DutyService::window($duty->duty_date, $duty->shift->start_time, $duty->shift->end_time, $timezone);

            if ($now->lt($start) || ! $now->lt($end)) {
                continue;
            }

            $key = ($duty->department_id ?? 0).'-'.($duty->ward_id ?? 0).'-'.$duty->shift_id;
            $group = &$groups[$key];
            $group['department'] = $duty->department ? ['id' => $duty->department->id, 'name' => $duty->department->name] : null;
            $group['ward'] = $duty->ward ? ['id' => $duty->ward->id, 'name' => $duty->ward->name] : null;
            $group['shift'] = [
                'id' => $duty->shift->id,
                'name' => $duty->shift->name,
                'startTime' => substr($duty->shift->start_time, 0, 5),
                'endTime' => substr($duty->shift->end_time, 0, 5),
            ];
            $group['practitioners'][] = [
                'id' => $duty->practitioner->id,
                'name' => $duty->practitioner->name,
                'role' => $duty->practitioner->role,
                'specialty' => $duty->practitioner->specialty,
            ];
        }

        return response()->json([
            'data' => array_values($groups),
            'meta' => [
                'asOf' => $now->toISOString(),
                'timezone' => $timezone,
                'date' => $now->toDateString(),
            ],
        ]);
    }

    protected function validateStore(Request $request): array
    {
        return $request->validate([
            'practitioner_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'ward_id' => ['nullable', 'integer', Rule::exists('wards', 'id')],
            'duty_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    protected function validateUpdate(Request $request): array
    {
        return $request->validate([
            'practitioner_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'shift_id' => ['sometimes', 'integer', Rule::exists('shifts', 'id')],
            'department_id' => ['sometimes', 'integer', Rule::exists('departments', 'id')],
            'ward_id' => ['sometimes', 'nullable', 'integer', Rule::exists('wards', 'id')],
            'duty_date' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }
}
