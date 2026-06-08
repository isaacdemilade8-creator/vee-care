<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\UserResource;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Message;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    private const ROLES = ['super_admin', 'admin', 'doctor', 'nurse', 'patient', 'lab_technician', 'pharmacist'];

    public function analytics(Request $request): JsonResponse
    {
        $userScope = User::query();
        $appointmentScope = Appointment::query();
        $recordScope = MedicalRecord::query();
        $prescriptionScope = Prescription::query();
        $isSuperAdmin = $request->user()->isRole('super_admin');

        if (! $isSuperAdmin) {
            $userScope->whereNot('role', 'super_admin');
        }

        return response()->json([
            'users' => [
                'total' => (clone $userScope)->count(),
                'patients' => (clone $userScope)->where('role', 'patient')->count(),
                'doctors' => (clone $userScope)->where('role', 'doctor')->count(),
                'admins' => (clone $userScope)->whereIn('role', ['super_admin', 'admin'])->count(),
                'staff' => (clone $userScope)->whereIn('role', ['doctor', 'nurse', 'lab_technician', 'pharmacist'])->count(),
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

        if (! $request->user()->isRole('super_admin')) {
            $query->whereNot('role', 'super_admin');
        }

        $query->when($request->string('role')->toString(), fn ($q, $role) => $q->where('role', $role))
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        return UserResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function storeUser(Request $request): UserResource
    {
        $allowedRoles = $request->user()->isRole('super_admin')
            ? self::ROLES
            : ['doctor', 'nurse', 'patient', 'lab_technician', 'pharmacist'];

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

        return new UserResource($user);
    }

    public function updateUser(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'specialty' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        if (! $request->user()->isRole('super_admin')) {
            abort_if(isset($data['role']) && in_array($data['role'], ['super_admin', 'admin'], true), 403);
        }

        $user->update($data);

        return new UserResource($user);
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->id === $user->id, 422, 'You cannot delete your own account.');

        if (! $request->user()->isRole('super_admin')) {
            abort_if($user->isRole('super_admin', 'admin'), 403, 'Admins cannot delete admin accounts.');
        }

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    public function appointments(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::query()->with(['patient', 'doctor', 'prescription'])->latest('scheduled_at');

        $query->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('doctor', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
                });
            });

        return AppointmentResource::collection($query->paginate($request->integer('per_page', 10)));
    }
}
