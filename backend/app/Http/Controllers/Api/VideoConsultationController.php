<?php

namespace App\Http\Controllers\Api;

use App\Events\VideoSignalSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VideoConsultationController extends Controller
{
    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        $this->authorizeParticipant($request, $appointment);

        abort_unless($appointment->status === 'approved', 403, 'Video consultations are available for approved appointments.');

        return new AppointmentResource($appointment->load(['patient', 'doctor']));
    }

    public function signal(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeParticipant($request, $appointment);

        abort_unless($appointment->status === 'approved', 403, 'Video consultations are available for approved appointments.');

        $data = $request->validate([
            'type' => ['required', Rule::in(['ready', 'offer', 'answer', 'ice-candidate', 'leave'])],
            'payload' => ['nullable', 'array'],
        ]);

        broadcast(new VideoSignalSent(
            $appointment,
            $request->user(),
            $data['type'],
            $data['payload'] ?? [],
        ));

        return response()->json(['message' => 'Signal sent.']);
    }

    private function authorizeParticipant(Request $request, Appointment $appointment): void
    {
        $user = $request->user();

        abort_unless(
            $appointment->patient_id === $user->id || $appointment->doctor_id === $user->id,
            403,
            'You are not part of this consultation.'
        );
    }
}
