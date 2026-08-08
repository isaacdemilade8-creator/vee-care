<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Message;
use App\Models\Prescription;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function analytics(Request $request): JsonResponse
    {
        $userScope = User::query();
        $appointmentScope = Appointment::query();
        $recordScope = MedicalRecord::query();
        $prescriptionScope = Prescription::query();

        return response()->json([
            'users' => [
                'total' => (clone $userScope)->count(),
                'patients' => (clone $userScope)->where('role', Role::Patient->value)->count(),
                'doctors' => (clone $userScope)->where('role', Role::Doctor->value)->count(),
                'admins' => (clone $userScope)->where('role', Role::HospitalAdmin->value)->count(),
                'staff' => (clone $userScope)->whereIn('role', Role::staff())->count(),
            ],
            'appointments' => [
                'total' => (clone $appointmentScope)->count(),
                'pending' => (clone $appointmentScope)->where('status', 'pending')->count(),
                'approved' => (clone $appointmentScope)->where('status', 'approved')->count(),
                'completed' => (clone $appointmentScope)->where('status', 'completed')->count(),
                'rejected' => (clone $appointmentScope)->where('status', 'rejected')->count(),
            ],
            'medicalRecords' => $recordScope->count(),
            'messages' => Message::count(),
            'prescriptions' => $prescriptionScope->count(),
        ]);
    }

    public function users(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->latest();

        $query->when($request->string('role')->toString(), fn ($q, $role) => $q->where('role', $role))
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        return UserResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function storeUser(Request $request, AuditService $audit): UserResource
    {
        $allowedRoles = Role::assignableByAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in($allowedRoles)],
            'specialty' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
        ]);

        $audit->record($request, 'admin.user_created', $user);

        return new UserResource($user);
    }

    public function updateUser(Request $request, User $user, AuditService $audit): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(Role::values())],
            'specialty' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        if ($user->isRole(Role::HospitalAdmin)) {
            abort_if($user->id !== $request->user()->id, 403, 'Hospital admin accounts cannot be reassigned.');
        }

        $user->update($data);

        $audit->record($request, 'admin.user_updated', $user);

        return new UserResource($user);
    }

    public function destroyUser(Request $request, User $user, AuditService $audit): JsonResponse
    {
        abort_if($request->user()->id === $user->id, 422, 'You cannot delete your own account.');

        abort_if($user->isRole(Role::HospitalAdmin), 403, 'Hospital admin accounts cannot be deleted.');

        $audit->record($request, 'admin.user_deleted', $user, ['name' => $user->name]);

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    public function appointments(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::query()->with(['patient', 'doctor', 'prescription'])->latest('scheduled_at');

        $query->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('doctor', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
                });
            });

        return AppointmentResource::collection($query->paginate($request->integer('per_page', 10)));
    }
}
