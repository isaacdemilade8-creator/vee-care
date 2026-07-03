<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        $user = $request->user();

        abort_unless(
            $user->isRole('super_admin', 'admin') || $appointment->patient_id === $user->id || $appointment->doctor_id === $user->id,
            403
        );

        return new AppointmentResource($appointment->load(['patient', 'doctor', 'prescription']));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Appointment::query()->with(['patient', 'doctor', 'prescription'])->latest('scheduled_at');

        if ($user->isRole('patient')) {
            $query->where('patient_id', $user->id);
        } elseif ($user->isRole('doctor')) {
            $query->where('doctor_id', $user->id);
        }

        $query->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('reason', 'like', "%{$search}%")
                        ->orWhereHas('patient', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('doctor', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
                });
            });

        return AppointmentResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function store(Request $request, AuditService $audit, NotificationService $notifications): AppointmentResource
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'exists:users,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $doctor = User::findOrFail($data['doctor_id']);
        abort_unless($doctor->isRole('doctor'), 422, 'Selected user is not a doctor.');

        $appointment = Appointment::create([
            ...$data,
            'organization_id' => null,
            'branch_id' => null,
            'patient_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        $appointment->load(['patient', 'doctor']);

        $audit->record($request, 'appointment.created', $appointment);
        $notifications->send(
            $appointment->doctor,
            'appointment.booked',
            'New appointment request',
            "{$appointment->patient->name} requested an appointment for {$appointment->scheduled_at->format('M j, Y g:i A')}.",
            ['appointmentId' => $appointment->id]
        );

        return new AppointmentResource($appointment);
    }

    public function update(Request $request, AuditService $audit, Appointment $appointment, NotificationService $notifications): AppointmentResource
    {
        $user = $request->user();
        abort_unless(
            $user->isRole('super_admin')
                || $appointment->doctor_id === $user->id
                || $user->isRole('admin'),
            403
        );

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected', 'completed'])],
            'notes' => ['nullable', 'string'],
        ]);

        $appointment->update($data);
        $appointment->load(['patient', 'doctor', 'prescription']);

        $action = match ($appointment->status) {
            'approved' => 'appointment.approved',
            'rejected' => 'appointment.rejected',
            'completed' => 'appointment.completed',
            default => 'appointment.updated',
        };

        $audit->record($request, $action, $appointment, ['status' => $appointment->status]);
        $notifications->send(
            $appointment->patient,
            'appointment.status_changed',
            'Appointment '.$appointment->status,
            "Your appointment with {$appointment->doctor->name} was {$appointment->status}.",
            ['appointmentId' => $appointment->id, 'status' => $appointment->status]
        );

        return new AppointmentResource($appointment);
    }

    public function doctors(Request $request): AnonymousResourceCollection
    {
        return \App\Http\Resources\UserResource::collection(
            User::query()
                ->where('role', 'doctor')
                ->withCount('practitionerReviews')
                ->withAvg('practitionerReviews', 'rating')
                ->when($request->string('specialty')->toString(), fn ($query, $specialty) => $query->where('specialty', $specialty))
                ->when($request->integer('min_rating'), fn ($query, $rating) => $query->having('practitioner_reviews_avg_rating', '>=', $rating))
                ->when($request->string('search')->toString(), function ($query, $search): void {
                    $query->where(function ($searchQuery) use ($search): void {
                        $searchQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('specialty', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->paginate(50)
        );
    }
}
