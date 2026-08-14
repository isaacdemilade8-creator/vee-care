<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdmissionResource;
use App\Http\Resources\BedResource;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inpatient admission management.
 *
 * Runs inside the tenant database (ResolveTenant switches the connection
 * before these routes execute), so route-model-bound admissions can only ever
 * resolve to a record of the request's own hospital: another tenant's id
 * yields a 404. The route group adds `role:hospital_admin`, so only hospital
 * administrators can reach any of these endpoints.
 *
 * The admission carries the full structure chain (department -> ward -> room ->
 * bed). `store` validates that every reference resolves through the same
 * active chain, then hands the actual bed assignment to AdmissionService which
 * locks the bed row and marks it occupied inside a transaction. Discharge is
 * the only legal transition from `admitted`; it releases the bed back to
 * `available` transactionally. Editing an admission updates metadata only
 * (reason, notes, attending practitioner) — transferring a patient between
 * beds is intentionally not supported here (discharge + re-admit instead).
 */
class AdmissionController extends Controller
{
    protected const STATUSES = ['admitted', 'discharged'];

    /*
    |--------------------------------------------------------------------------
    | CRUD
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Admission::query()
            ->with(['patient.patientProfile', 'practitioner', 'department', 'ward', 'room', 'bed.room.ward'])
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->string('department_id')->toString(), fn ($q, string $id) => $q->where('department_id', $id))
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q->where('ward_id', $id))
            ->when($request->string('room_id')->toString(), fn ($q, string $id) => $q->where('room_id', $id))
            ->when($request->string('bed_id')->toString(), fn ($q, string $id) => $q->where('bed_id', $id))
            ->when($request->string('search')->toString(), function ($q, string $search): void {
                $q->where(fn ($nested) => $nested
                    ->whereHas('patient', fn ($patient) => $patient
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('patient.patientProfile', fn ($profile) => $profile
                        ->where('patient_number', 'like', "%{$search}%")));
            })
            ->when($request->string('admitted_after')->toString(), fn ($q, string $date) => $q->whereDate('admitted_at', '>=', $date))
            ->when($request->string('admitted_before')->toString(), fn ($q, string $date) => $q->whereDate('admitted_at', '<=', $date))
            ->latest('admitted_at');

        return AdmissionResource::collection($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request, AdmissionService $service, AuditService $audit): AdmissionResource
    {
        $data = $this->validateStore($request);

        $this->validatePatient((int) $data['patient_id']);

        if (! empty($data['practitioner_id'])) {
            $this->validatePractitioner((int) $data['practitioner_id']);
        }

        $this->validateStructureChain(
            (int) $data['bed_id'],
            ! empty($data['department_id']) ? (int) $data['department_id'] : null,
            (int) $data['ward_id'],
            (int) $data['room_id'],
        );

        $admission = $service->admit($data);
        $admission->load(['patient.patientProfile', 'practitioner', 'department', 'ward', 'room', 'bed.room.ward']);

        $audit->record($request, 'admission.created', $admission, [
            'patient_id' => $admission->patient_id,
            'bed_id' => $admission->bed_id,
        ]);
        $audit->record($request, 'bed.assigned', $admission->bed, [
            'admission_id' => $admission->id,
            'bed_number' => $admission->bed?->bed_number,
        ]);

        return new AdmissionResource($admission);
    }

    public function show(Admission $admission): AdmissionResource
    {
        return new AdmissionResource($admission->load(['patient.patientProfile', 'practitioner', 'department', 'ward', 'room', 'bed.room.ward']));
    }

    public function update(Request $request, Admission $admission, AuditService $audit): AdmissionResource
    {
        $data = $request->validate([
            'practitioner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('practitioner_id', $data) && ! empty($data['practitioner_id'])) {
            $this->validatePractitioner((int) $data['practitioner_id']);
        }

        $admission->update($data);
        $admission->load(['patient.patientProfile', 'practitioner', 'department', 'ward', 'room', 'bed.room.ward']);

        $audit->record($request, 'admission.updated', $admission, ['changed' => array_keys($data)]);

        return new AdmissionResource($admission);
    }

    public function discharge(Request $request, Admission $admission, AdmissionService $service, AuditService $audit): AdmissionResource
    {
        $data = $request->validate([
            'discharged_at' => ['sometimes', 'date', 'after_or_equal:admitted_at'],
        ]);

        $dischargedAt = isset($data['discharged_at']) ? Carbon::parse($data['discharged_at']) : null;
        $admission = $service->discharge($admission, $dischargedAt);
        $admission->load(['patient.patientProfile', 'practitioner', 'department', 'ward', 'room', 'bed.room.ward']);

        $audit->record($request, 'admission.discharged', $admission, ['bed_id' => $admission->bed_id]);

        if ($admission->bed) {
            $audit->record($request, 'bed.released', $admission->bed, ['admission_id' => $admission->id]);
        }

        return new AdmissionResource($admission);
    }

    public function destroy(Request $request, Admission $admission, AdmissionService $service, AuditService $audit): JsonResponse
    {
        $wasActive = ! $admission->isDischarged();
        $bed = $admission->bed;

        $service->remove($admission);

        $audit->record($request, 'admission.deleted', $admission, ['patient_id' => $admission->patient_id]);

        if ($wasActive && $bed) {
            $audit->record($request, 'bed.released', $bed, ['admission_id' => $admission->id]);
        }

        return response()->json(['message' => 'Admission deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Bed availability & ward occupancy
    |--------------------------------------------------------------------------
    */

    /**
     * Beds currently assignable to a new admission: in service, `available`
     * and reachable through an active room and ward. Optionally narrowed to a
     * department, ward or room — the create form drives its dependent selects
     * from these filters.
     */
    public function availability(Request $request): AnonymousResourceCollection
    {
        $query = Bed::query()
            ->with(['room.ward.department'])
            ->where('is_active', true)
            ->where('status', 'available')
            ->whereHas('room', fn ($room) => $room
                ->where('status', 'active')
                ->whereHas('ward', fn ($ward) => $ward->where('status', 'active')))
            ->when($request->string('department_id')->toString(), fn ($q, string $id) => $q
                ->whereHas('room.ward', fn ($ward) => $ward->where('department_id', $id)))
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q
                ->whereHas('room.ward', fn ($ward) => $ward->whereKey($id)))
            ->when($request->string('room_id')->toString(), fn ($q, string $id) => $q->where('room_id', $id))
            ->latest();

        return BedResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    /**
     * Per-ward occupancy summary: declared capacity plus the current bed state
     * split (total / available / occupied / reserved / unavailable) and the
     * room -> bed listing so the dashboard renders without extra requests.
     */
    public function occupancy(Request $request): JsonResponse
    {
        $wards = Ward::query()
            ->with('department')
            ->with(['rooms.beds'])
            ->when($request->string('department_id')->toString(), fn ($q, string $id) => $q->where('department_id', $id))
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q->whereKey($id))
            ->latest()
            ->paginate($request->integer('per_page', 10));

        $data = $wards->map(fn (Ward $ward): array => [
            'id' => $ward->id,
            'name' => $ward->name,
            'department' => $ward->department
                ? ['id' => $ward->department->id, 'name' => $ward->department->name]
                : null,
            'capacity' => $ward->capacity,
            'totalBeds' => $ward->rooms->sum(fn ($room) => $room->beds->count()),
            'availableBeds' => $ward->rooms->sum(fn ($room) => $room->beds->where('status', 'available')->count()),
            'occupiedBeds' => $ward->rooms->sum(fn ($room) => $room->beds->where('status', 'occupied')->count()),
            'reservedBeds' => $ward->rooms->sum(fn ($room) => $room->beds->where('status', 'reserved')->count()),
            'unavailableBeds' => $ward->rooms->sum(fn ($room) => $room->beds->where('status', 'unavailable')->count()),
            'rooms' => $ward->rooms->map(fn ($room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'capacity' => $room->capacity,
                'beds' => $room->beds->map(fn (Bed $bed): array => [
                    'id' => $bed->id,
                    'bedNumber' => $bed->bed_number,
                    'status' => $bed->status,
                    'isActive' => (bool) $bed->is_active,
                ])->values(),
            ])->values(),
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $wards->currentPage(),
                'last_page' => $wards->lastPage(),
                'total' => $wards->total(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function validateStore(Request $request): array
    {
        return $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'ward_id' => ['required', 'integer', Rule::exists('wards', 'id')],
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')],
            'bed_id' => ['required', 'integer', Rule::exists('beds', 'id')],
            'practitioner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'admitted_at' => ['sometimes', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    protected function validatePatient(int $patientId): void
    {
        $patient = User::find($patientId);

        if (! $patient || ! $patient->isRole(Role::Patient)) {
            throw ValidationException::withMessages([
                'patient_id' => ['The selected user is not a patient.'],
            ]);
        }

        if (! $patient->is_active) {
            throw ValidationException::withMessages([
                'patient_id' => ['The selected patient is inactive.'],
            ]);
        }
    }

    protected function validatePractitioner(int $practitionerId): void
    {
        $practitioner = User::find($practitionerId);

        if (! $practitioner || ! $practitioner->isRole(Role::Doctor, Role::Nurse)) {
            throw ValidationException::withMessages([
                'practitioner_id' => ['The selected user is not a doctor or nurse.'],
            ]);
        }

        if (! $practitioner->is_active) {
            throw ValidationException::withMessages([
                'practitioner_id' => ['The selected practitioner is inactive.'],
            ]);
        }
    }

    /**
     * Every structural reference must resolve through one consistent, active
     * chain: bed belongs to the given room, the room to the given ward, and
     * the ward to the given department (or to none).
     */
    protected function validateStructureChain(int $bedId, ?int $departmentId, int $wardId, int $roomId): void
    {
        $bed = Bed::query()->with(['room.ward.department'])->find($bedId);

        if (! $bed || $bed->room_id !== $roomId || $bed->room->ward_id !== $wardId || $bed->room->ward->department_id !== $departmentId) {
            throw ValidationException::withMessages([
                'bed_id' => ['The bed, room, ward and department do not form a valid chain.'],
            ]);
        }

        if (! $bed->is_active) {
            throw ValidationException::withMessages([
                'bed_id' => ['The selected bed is out of service.'],
            ]);
        }

        if ($bed->status !== 'available') {
            throw ValidationException::withMessages([
                'bed_id' => ['The selected bed is not available.'],
            ]);
        }

        if ($bed->room->status !== 'active') {
            throw ValidationException::withMessages([
                'room_id' => ['The selected room is not active.'],
            ]);
        }

        if ($bed->room->ward->status !== 'active') {
            throw ValidationException::withMessages([
                'ward_id' => ['The selected ward is not active.'],
            ]);
        }

        if ($departmentId !== null && $bed->room->ward->department?->status !== 'active') {
            throw ValidationException::withMessages([
                'department_id' => ['The selected department is not active.'],
            ]);
        }
    }
}
