<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrescriptionResource;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrescriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Prescription::query()->with(['patient', 'doctor'])->latest('issued_at');

        if ($user->isRole('patient')) {
            $query->where('patient_id', $user->id);
        } elseif ($user->isRole('doctor')) {
            $query->where('doctor_id', $user->id);
        }

        return PrescriptionResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function store(Request $request, AuditService $audit, NotificationService $notifications): PrescriptionResource
    {
        $data = $request->validate([
            'appointment_id' => ['required', 'exists:appointments,id'],
            'medication' => ['required', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string'],
        ]);

        $appointment = Appointment::findOrFail($data['appointment_id']);
        abort_unless($appointment->doctor_id === $request->user()->id, 403);

        $prescription = Prescription::updateOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'medication' => $data['medication'],
                'dosage' => $data['dosage'],
                'instructions' => $data['instructions'],
                'issued_at' => now(),
            ]
        );

        $appointment->update(['status' => 'completed']);
        $prescription->load(['patient', 'doctor']);

        $audit->record($request, 'prescription.created', $prescription);
        $notifications->send(
            $prescription->patient,
            'prescription.created',
            'Prescription issued',
            "{$prescription->doctor->name} issued a prescription for {$prescription->medication}.",
            ['prescriptionId' => $prescription->id, 'appointmentId' => $appointment->id]
        );

        return new PrescriptionResource($prescription);
    }
}
