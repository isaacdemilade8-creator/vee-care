<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BedResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\WardResource;
use App\Models\Bed;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Ward;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hospital structure management: departments, wards, rooms and beds.
 *
 * Runs inside the tenant database (ResolveTenant switches the connection
 * before these routes execute), so route-model-bound entities can only ever
 * resolve to a record of the request's own hospital: another tenant's id
 * yields a 404. The route group adds `role:hospital_admin`, so only hospital
 * administrators can reach any of these endpoints. Capacity values are kept
 * consistent across the hierarchy (ward capacity >= sum of room capacities,
 * room capacity >= its beds) and unsafe deletion of an entity with dependents
 * is rejected in favour of deactivation.
 */
class HospitalStructureController extends Controller
{
    protected const STATUSES = ['active', 'inactive'];

    protected const BED_STATUSES = ['available', 'occupied', 'reserved', 'unavailable'];

    protected const WARD_TYPES = [
        'general',
        'private',
        'semi_private',
        'isolation',
        'icu',
        'maternity',
        'pediatric',
        'surgical',
        'emergency',
    ];

    /*
    |--------------------------------------------------------------------------
    | Departments
    |--------------------------------------------------------------------------
    */

    public function indexDepartments(Request $request): AnonymousResourceCollection
    {
        $query = Department::query()
            ->withCount('wards')
            ->when($request->string('search')->toString(), function ($q, string $search): void {
                $q->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%"));
            })
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->latest();

        return DepartmentResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function storeDepartment(Request $request, AuditService $audit): DepartmentResource
    {
        $data = $this->validateDepartment($request);

        $department = Department::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $audit->record($request, 'department.created', $department, ['name' => $department->name]);

        return new DepartmentResource($department->loadCount('wards'));
    }

    public function showDepartment(Department $department): DepartmentResource
    {
        return new DepartmentResource($department->loadCount('wards'));
    }

    public function updateDepartment(Request $request, Department $department, AuditService $audit): DepartmentResource
    {
        $data = $this->validateDepartment($request, $department);

        $department->update($data);

        $audit->record($request, 'department.updated', $department, ['changed' => array_keys($data)]);

        return new DepartmentResource($department->loadCount('wards'));
    }

    public function destroyDepartment(Request $request, Department $department, AuditService $audit): JsonResponse
    {
        $this->abortIfHasDependents(
            $department->wards()->exists(),
            'Cannot delete a department that still has wards. Deactivate it instead.',
        );

        $audit->record($request, 'department.deleted', $department, ['name' => $department->name]);

        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Wards
    |--------------------------------------------------------------------------
    */

    public function indexWards(Request $request): AnonymousResourceCollection
    {
        $query = Ward::query()
            ->with('department')
            ->withCount(['rooms', 'beds'])
            ->when($request->string('search')->toString(), function ($q, string $search): void {
                $q->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhereHas('department', fn ($dept) => $dept->where('name', 'like', "%{$search}%")));
            })
            ->when($request->string('department_id')->toString(), fn ($q, string $id) => $q->where('department_id', $id))
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->latest();

        return WardResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function storeWard(Request $request, AuditService $audit): WardResource
    {
        $data = $this->validateWard($request);

        $ward = Ward::create([
            'department_id' => $data['department_id'] ?? null,
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'capacity' => $data['capacity'],
            'status' => $data['status'] ?? 'active',
        ]);

        $audit->record($request, 'ward.created', $ward, ['name' => $ward->name, 'capacity' => $ward->capacity]);

        return new WardResource($ward->load('department')->loadCount(['rooms', 'beds']));
    }

    public function showWard(Ward $ward): WardResource
    {
        return new WardResource($ward->load('department')->loadCount(['rooms', 'beds']));
    }

    public function updateWard(Request $request, Ward $ward, AuditService $audit): WardResource
    {
        $data = $this->validateWard($request, $ward);

        $capacity = (int) ($data['capacity'] ?? $ward->capacity);
        $this->assertWardCapacity($ward, $capacity);

        $ward->update($data);

        $audit->record($request, 'ward.updated', $ward, ['changed' => array_keys($data)]);

        return new WardResource($ward->load('department')->loadCount(['rooms', 'beds']));
    }

    public function destroyWard(Request $request, Ward $ward, AuditService $audit): JsonResponse
    {
        $this->abortIfHasDependents(
            $ward->rooms()->exists(),
            'Cannot delete a ward that still has rooms. Deactivate it instead.',
        );

        $audit->record($request, 'ward.deleted', $ward, ['name' => $ward->name]);

        $ward->delete();

        return response()->json(['message' => 'Ward deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Rooms
    |--------------------------------------------------------------------------
    */

    public function indexRooms(Request $request): AnonymousResourceCollection
    {
        $query = Room::query()
            ->with('ward')
            ->withCount('beds')
            ->when($request->string('search')->toString(), function ($q, string $search): void {
                $q->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('ward', fn ($ward) => $ward->where('name', 'like', "%{$search}%")));
            })
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q->where('ward_id', $id))
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->latest();

        return RoomResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function storeRoom(Request $request, AuditService $audit): RoomResource
    {
        $data = $this->validateRoom($request);

        $ward = Ward::findOrFail($data['ward_id']);
        $this->assertWardCapacityAllows($ward, $data['capacity']);

        $room = Room::create([
            'ward_id' => $data['ward_id'],
            'name' => $data['name'],
            'capacity' => $data['capacity'],
            'status' => $data['status'] ?? 'active',
        ]);

        $audit->record($request, 'room.created', $room, ['name' => $room->name, 'capacity' => $room->capacity]);

        return new RoomResource($room->load('ward')->loadCount('beds'));
    }

    public function showRoom(Room $room): RoomResource
    {
        return new RoomResource($room->load('ward')->loadCount('beds'));
    }

    public function updateRoom(Request $request, Room $room, AuditService $audit): RoomResource
    {
        $data = $this->validateRoom($request, $room);

        $newWardId = (int) ($data['ward_id'] ?? $room->ward_id);
        $newCapacity = (int) ($data['capacity'] ?? $room->capacity);

        $this->assertRoomCapacityAtLeastBeds($room, $newCapacity);
        $this->assertWardCapacityAllows(Ward::findOrFail($newWardId), $newCapacity, exceptRoomId: $room->id);

        $room->update($data);

        $audit->record($request, 'room.updated', $room, ['changed' => array_keys($data)]);

        return new RoomResource($room->load('ward')->loadCount('beds'));
    }

    public function destroyRoom(Request $request, Room $room, AuditService $audit): JsonResponse
    {
        $this->abortIfHasDependents(
            $room->beds()->exists(),
            'Cannot delete a room that still has beds. Deactivate it instead.',
        );

        $audit->record($request, 'room.deleted', $room, ['name' => $room->name]);

        $room->delete();

        return response()->json(['message' => 'Room deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Beds
    |--------------------------------------------------------------------------
    */

    public function indexBeds(Request $request): AnonymousResourceCollection
    {
        $query = Bed::query()
            ->with(['room.ward'])
            ->when($request->string('search')->toString(), fn ($q, string $search) => $q->where('bed_number', 'like', "%{$search}%"))
            ->when($request->string('room_id')->toString(), fn ($q, string $id) => $q->where('room_id', $id))
            ->when($request->string('ward_id')->toString(), fn ($q, string $id) => $q->whereHas('room', fn ($room) => $room->where('ward_id', $id)))
            ->when($request->string('status')->toString(), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->string('is_active')->toString(), function ($q, string $value): void {
                $q->where('is_active', $value === 'true' || $value === '1');
            })
            ->latest();

        return BedResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function storeBed(Request $request, AuditService $audit): BedResource
    {
        $data = $this->validateBed($request);

        $room = Room::findOrFail($data['room_id']);
        $this->assertRoomHasBedCapacity($room);

        $bed = Bed::create([
            'room_id' => $data['room_id'],
            'bed_number' => $data['bed_number'],
            'status' => $data['status'] ?? 'available',
            'is_active' => $data['is_active'] ?? true,
        ]);

        $audit->record($request, 'bed.created', $bed, ['bed_number' => $bed->bed_number]);

        return new BedResource($bed->load(['room.ward']));
    }

    public function showBed(Bed $bed): BedResource
    {
        return new BedResource($bed->load(['room.ward']));
    }

    public function updateBed(Request $request, Bed $bed, AuditService $audit): BedResource
    {
        $data = $this->validateBed($request, $bed);

        $newRoomId = (int) ($data['room_id'] ?? $bed->room_id);

        if ($newRoomId !== $bed->room_id) {
            $this->assertRoomHasBedCapacity(Room::findOrFail($newRoomId));
        }

        // A deactivated bed must not keep an active assignment state.
        $nextActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $bed->is_active;
        $nextStatus = $data['status'] ?? $bed->status;

        if (! $nextActive && in_array($nextStatus, ['occupied', 'reserved'], true)) {
            throw ValidationException::withMessages([
                'is_active' => ['An occupied or reserved bed cannot be deactivated.'],
            ]);
        }

        $bed->update($data);

        $audit->record($request, 'bed.updated', $bed, ['changed' => array_keys($data)]);

        return new BedResource($bed->load(['room.ward']));
    }

    public function destroyBed(Request $request, Bed $bed, AuditService $audit): JsonResponse
    {
        $audit->record($request, 'bed.deleted', $bed, ['bed_number' => $bed->bed_number]);

        $bed->delete();

        return response()->json(['message' => 'Bed deleted.']);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function validateDepartment(Request $request, ?Department $department = null): array
    {
        return $request->validate([
            'name' => [
                $department ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('departments', 'name')
                    ->where('organization_id', $this->organizationId($request))
                    ->ignore($department?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ]);
    }

    protected function validateWard(Request $request, ?Ward $ward = null): array
    {
        return $request->validate([
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'name' => [
                $ward ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('wards', 'name')
                    ->where('organization_id', $this->organizationId($request))
                    ->ignore($ward?->id),
            ],
            'type' => ['nullable', Rule::in(self::WARD_TYPES)],
            'capacity' => [$ward ? 'sometimes' : 'required', 'integer', 'min:0', 'max:10000'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ]);
    }

    protected function validateRoom(Request $request, ?Room $room = null): array
    {
        $wardId = $room
            ? (int) ($request->input('ward_id') ?? $room->ward_id)
            : (int) $request->input('ward_id');

        $rules = [
            'ward_id' => [$room ? 'sometimes' : 'required', 'integer', Rule::exists('wards', 'id')],
            'capacity' => [$room ? 'sometimes' : 'required', 'integer', 'min:1', 'max:10000'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ];

        $rules['name'] = [
            $room ? 'sometimes' : 'required',
            'string',
            'max:255',
            Rule::unique('rooms', 'name')
                ->where('organization_id', $this->organizationId($request))
                ->where('ward_id', $wardId)
                ->ignore($room?->id),
        ];

        return $request->validate($rules);
    }

    protected function validateBed(Request $request, ?Bed $bed = null): array
    {
        $roomId = $bed
            ? (int) ($request->input('room_id') ?? $bed->room_id)
            : (int) $request->input('room_id');

        $rules = [
            'room_id' => [$bed ? 'sometimes' : 'required', 'integer', Rule::exists('rooms', 'id')],
            'status' => ['sometimes', Rule::in(self::BED_STATUSES)],
            'is_active' => ['sometimes', 'boolean'],
        ];

        $rules['bed_number'] = [
            $bed ? 'sometimes' : 'required',
            'string',
            'max:60',
            Rule::unique('beds', 'bed_number')
                ->where('organization_id', $this->organizationId($request))
                ->where('room_id', $roomId)
                ->ignore($bed?->id),
        ];

        return $request->validate($rules);
    }

    /*
    |--------------------------------------------------------------------------
    | Capacity & deletion guards
    |--------------------------------------------------------------------------
    */

    protected function assertWardCapacity(Ward $ward, int $capacity): void
    {
        if ($capacity < $ward->rooms()->sum('capacity')) {
            throw ValidationException::withMessages([
                'capacity' => ['Ward capacity cannot be below the total capacity of its rooms.'],
            ]);
        }
    }

    protected function assertWardCapacityAllows(Ward $ward, int $roomCapacity, int $exceptRoomId = 0): void
    {
        $otherRoomsCapacity = (int) $ward->rooms()->whereKeyNot($exceptRoomId)->sum('capacity');

        if ($otherRoomsCapacity + $roomCapacity > $ward->capacity) {
            throw ValidationException::withMessages([
                'capacity' => ['The room capacity would exceed the ward capacity ('.$ward->capacity.').'],
            ]);
        }
    }

    protected function assertRoomCapacityAtLeastBeds(Room $room, int $capacity): void
    {
        if ($capacity < $room->beds()->count()) {
            throw ValidationException::withMessages([
                'capacity' => ['Room capacity cannot be below its current number of beds.'],
            ]);
        }
    }

    protected function assertRoomHasBedCapacity(Room $room): void
    {
        if ($room->beds()->count() >= $room->capacity) {
            throw ValidationException::withMessages([
                'room_id' => ['The room has reached its bed capacity ('.$room->capacity.').'],
            ]);
        }
    }

    protected function abortIfHasDependents(bool $hasDependents, string $message): void
    {
        abort_if($hasDependents, 422, $message);
    }

    protected function organizationId(Request $request): int
    {
        return $request->user()->organization_id
            ? (int) $request->user()->organization_id
            : (int) Organization::query()->value('id');
    }
}
