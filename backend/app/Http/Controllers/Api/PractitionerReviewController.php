<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PractitionerReviewResource;
use App\Models\Appointment;
use App\Models\PractitionerReview;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PractitionerReviewController extends Controller
{
    public function index(User $user, Request $request): AnonymousResourceCollection
    {
        $reviews = PractitionerReview::query()
            ->with('patient')
            ->where('practitioner_id', $user->id)
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return PractitionerReviewResource::collection($reviews);
    }

    public function store(User $user, Request $request, AuditService $audit): PractitionerReviewResource
    {
        abort_unless($request->user()->isRole('patient'), 403);
        abort_unless($user->isRole('doctor', 'nurse', 'lab_technician', 'pharmacist'), 422, 'Only practitioners can be reviewed.');

        $data = $request->validate([
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1200'],
        ]);

        $appointment = $this->reviewableAppointment($request->user(), $user, $data['appointment_id'] ?? null);
        abort_unless($appointment, 403, 'You can rate this practitioner after a completed service.');

        $review = PractitionerReview::updateOrCreate(
            [
                'patient_id' => $request->user()->id,
                'practitioner_id' => $user->id,
                'appointment_id' => $appointment->id,
            ],
            [
                'organization_id' => null,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
            ]
        );

        $audit->record($request, 'review.created', $review);

        return new PractitionerReviewResource($review->load(['patient', 'practitioner']));
    }

    private function reviewableAppointment(User $patient, User $practitioner, ?int $appointmentId): ?Appointment
    {
        return Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('doctor_id', $practitioner->id)
            ->where('status', 'completed')
            ->when($appointmentId, fn ($query) => $query->whereKey($appointmentId))
            ->latest('scheduled_at')
            ->first();
    }
}
