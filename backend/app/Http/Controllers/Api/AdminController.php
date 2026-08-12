<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Message;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
