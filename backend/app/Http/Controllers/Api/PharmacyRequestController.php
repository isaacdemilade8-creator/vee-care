<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PharmacyRequestResource;
use App\Models\Medicine;
use App\Models\PharmacyRequest;
use App\Models\PharmacyRequestItem;
use App\Models\Prescription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PharmacyRequestController extends Controller
{
    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $user = $request->user();

        $requests = PharmacyRequest::query()
            ->with(['patient', 'doctor', 'reviewedBy', 'items.medicine', 'items.dispensedBy', 'items.givenBy'])
            ->when($user->isRole('doctor'), fn ($query) => $query->where('doctor_id', $user->id))
            ->when($user->isRole('patient'), fn ($query) => $query->where('patient_id', $user->id))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return PharmacyRequestResource::collection($requests);
    }

    public function show(Request $request, PharmacyRequest $pharmacyRequest): PharmacyRequestResource
    {
        $this->authorizeView($request, $pharmacyRequest);

        return new PharmacyRequestResource(
            $pharmacyRequest->load(['patient', 'doctor', 'reviewedBy', 'items.medicine', 'items.dispensedBy', 'items.givenBy'])
        );
    }

    public function store(Request $request, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        abort_unless($request->user()->isRole('doctor'), 403);

        $data = $request->validate([
            'patient_id' => ['required', 'exists:users,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'clinical_note' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.medicine_id' => ['nullable', 'exists:medicines,id'],
            'items.*.medication_name' => ['nullable', 'string', 'max:255'],
            'items.*.dosage' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $patient = User::findOrFail($data['patient_id']);
        abort_unless($patient->isRole('patient'), 422, 'Selected user is not a patient.');

        if (! empty($data['appointment_id'])) {
            $appointment = \App\Models\Appointment::findOrFail($data['appointment_id']);
            abort_unless($appointment->doctor_id === $request->user()->id, 403);
            abort_unless($appointment->patient_id === $patient->id, 422, 'Appointment does not belong to this patient.');
        }

        $pharmacyRequest = DB::transaction(function () use ($data, $request, $patient): PharmacyRequest {
            $pharmacyRequest = PharmacyRequest::create([
                'organization_id' => null,
                'patient_id' => $patient->id,
                'doctor_id' => $request->user()->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'clinical_note' => $data['clinical_note'],
                'status' => 'pending_review',
            ]);

            foreach ($data['items'] as $item) {
                $medicine = ! empty($item['medicine_id']) ? Medicine::find($item['medicine_id']) : null;
                $medicationName = $medicine?->name ?? trim($item['medication_name'] ?? '');

                abort_if($medicationName === '', 422, 'Each drug must have a medicine selected or a medication name.');

                PharmacyRequestItem::create([
                    'pharmacy_request_id' => $pharmacyRequest->id,
                    'medicine_id' => $medicine?->id,
                    'medication_name' => $medicationName,
                    'dosage' => $item['dosage'] ?? $medicine?->strength,
                    'quantity' => $item['quantity'] ?? 1,
                    'instructions' => $item['instructions'] ?? null,
                    'availability_status' => 'pending',
                ]);
            }

            return $pharmacyRequest;
        });

        $pharmacyRequest->load(['patient', 'doctor', 'items.medicine', 'items.dispensedBy', 'items.givenBy']);

        $audit->record($request, 'pharmacy_request.created', $pharmacyRequest, [
            'patient' => $patient->name,
            'items' => $pharmacyRequest->items->count(),
        ]);

        User::query()
            ->whereIn('role', ['admin', 'pharmacist', 'super_admin'])
            ->get()
            ->each(fn (User $user) => $notifications->send(
                $user,
                'pharmacy_request.created',
                'New pharmacy request',
                "{$request->user()->name} requested drugs for {$patient->name}.",
                ['pharmacyRequestId' => $pharmacyRequest->id, 'patientId' => $patient->id],
            ));

        return response()->json([
            'message' => 'Pharmacy request sent.',
            'request' => new PharmacyRequestResource($pharmacyRequest),
        ], 201);
    }

    public function updateItem(Request $request, PharmacyRequestItem $pharmacyRequestItem, AuditService $audit): JsonResponse
    {
        abort_unless($request->user()->isRole('pharmacist', 'admin', 'super_admin'), 403);

        $pharmacyRequest = $pharmacyRequestItem->pharmacyRequest;
        abort_if($pharmacyRequest->status === 'reviewed', 422, 'This request has already been reviewed.');

        $data = $request->validate([
            'availability_status' => ['required', Rule::in(['available', 'unavailable'])],
            'pharmacist_note' => ['nullable', 'string', 'max:500'],
        ]);

        $pharmacyRequestItem->update($data);

        $audit->record($request, 'pharmacy_request.item_updated', $pharmacyRequestItem, [
            'availability' => $data['availability_status'],
        ]);

        return response()->json([
            'message' => 'Drug availability updated.',
            'request' => new PharmacyRequestResource(
                $pharmacyRequest->load(['patient', 'doctor', 'reviewedBy', 'items.medicine', 'items.dispensedBy', 'items.givenBy'])
            ),
        ]);
    }

    public function completeReview(Request $request, PharmacyRequest $pharmacyRequest, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        abort_unless($request->user()->isRole('pharmacist', 'admin', 'super_admin'), 403);
        abort_if($pharmacyRequest->status === 'reviewed', 422, 'This request has already been reviewed.');

        $pharmacyRequest->load('items');

        if ($pharmacyRequest->items->contains(fn (PharmacyRequestItem $item) => $item->availability_status === 'pending')) {
            return response()->json(['message' => 'Mark every drug as available or unavailable before completing the review.'], 422);
        }

        $pharmacyRequest = DB::transaction(function () use ($pharmacyRequest, $request): PharmacyRequest {
            foreach ($pharmacyRequest->items as $item) {
                if ($item->availability_status !== 'available') {
                    continue;
                }

                $item->update(['dispense_status' => 'pending']);

                Prescription::create([
                    'appointment_id' => $pharmacyRequest->appointment_id,
                    'pharmacy_request_id' => $pharmacyRequest->id,
                    'patient_id' => $pharmacyRequest->patient_id,
                    'doctor_id' => $pharmacyRequest->doctor_id,
                    'medication' => $item->medication_name,
                    'dosage' => $item->dosage ?? 'As directed',
                    'instructions' => $item->instructions ?? $pharmacyRequest->clinical_note,
                    'issued_at' => now(),
                ]);
            }

            $pharmacyRequest->update([
                'status' => 'reviewed',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return $pharmacyRequest->refresh()->load(['patient', 'doctor', 'reviewedBy', 'items.medicine', 'items.dispensedBy', 'items.givenBy']);
        });

        $availableCount = $pharmacyRequest->items->where('availability_status', 'available')->count();
        $unavailableCount = $pharmacyRequest->items->where('availability_status', 'unavailable')->count();

        $audit->record($request, 'pharmacy_request.reviewed', $pharmacyRequest, [
            'available' => $availableCount,
            'unavailable' => $unavailableCount,
        ]);

        $summary = "{$availableCount} available, {$unavailableCount} unavailable.";

        $notifications->send(
            $pharmacyRequest->doctor,
            'pharmacy_request.reviewed',
            'Pharmacy review complete',
            "Pharmacy reviewed your request for {$pharmacyRequest->patient->name}. {$summary}",
            ['pharmacyRequestId' => $pharmacyRequest->id],
        );

        $notifications->send(
            $pharmacyRequest->patient,
            'pharmacy_request.reviewed',
            'Prescription update',
            "Your doctor's pharmacy request was reviewed. {$summary} Available medicines were added to your records.",
            ['pharmacyRequestId' => $pharmacyRequest->id],
        );

        return response()->json([
            'message' => 'Pharmacy review completed and patient records updated.',
            'request' => new PharmacyRequestResource($pharmacyRequest),
        ]);
    }

    public function dispenseItem(Request $request, PharmacyRequestItem $pharmacyRequestItem, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        abort_unless($request->user()->isRole('pharmacist', 'admin', 'super_admin'), 403);

        $pharmacyRequest = $pharmacyRequestItem->pharmacyRequest;
        abort_if($pharmacyRequest->status !== 'reviewed', 422, 'Pharmacy request must be reviewed first.');
        abort_if($pharmacyRequestItem->availability_status !== 'available', 422, 'Only available drugs can be dispensed.');
        abort_if($pharmacyRequestItem->dispense_status !== 'pending', 422, 'Drug has already been dispensed.');

        $pharmacyRequestItem->update([
            'dispense_status' => 'dispensed',
            'dispensed_by' => $request->user()->id,
            'dispensed_at' => now(),
        ]);

        $audit->record($request, 'pharmacy_request.item_dispensed', $pharmacyRequestItem);

        $notifications->send(
            $pharmacyRequest->doctor,
            'pharmacy_request.item_dispensed',
            'Drug ready',
            "{$pharmacyRequestItem->medication_name} for {$pharmacyRequest->patient->name} is ready for pickup.",
            ['pharmacyRequestId' => $pharmacyRequest->id, 'itemId' => $pharmacyRequestItem->id],
        );

        return response()->json([
            'message' => 'Drug marked as dispensed.',
            'request' => new PharmacyRequestResource(
                $pharmacyRequest->load(['patient', 'doctor', 'reviewedBy', 'items.medicine', 'items.dispensedBy', 'items.givenBy'])
            ),
        ]);
    }

    public function giveItem(Request $request, PharmacyRequestItem $pharmacyRequestItem, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        abort_unless($request->user()->isRole('pharmacist', 'admin', 'super_admin'), 403);

        $pharmacyRequest = $pharmacyRequestItem->pharmacyRequest;
        abort_if($pharmacyRequest->status !== 'reviewed', 422, 'Pharmacy request must be reviewed first.');
        abort_if($pharmacyRequestItem->availability_status !== 'available', 422, 'Only available drugs can be given.');
        abort_if($pharmacyRequestItem->dispense_status !== 'dispensed', 422, 'Drug must be dispensed first.');
        abort_if($pharmacyRequestItem->dispense_status === 'given', 422, 'Drug has already been given to patient.');

        $pharmacyRequestItem->update([
            'dispense_status' => 'given',
            'given_by' => $request->user()->id,
            'given_at' => now(),
        ]);

        $audit->record($request, 'pharmacy_request.item_given', $pharmacyRequestItem);

        $notifications->send(
            $pharmacyRequest->doctor,
            'pharmacy_request.item_given',
            'Drug given to patient',
            "{$pharmacyRequestItem->medication_name} has been given to {$pharmacyRequest->patient->name}.",
            ['pharmacyRequestId' => $pharmacyRequest->id, 'itemId' => $pharmacyRequestItem->id],
        );

        return response()->json([
            'message' => 'Drug marked as given to patient.',
            'request' => new PharmacyRequestResource(
                $pharmacyRequest->load(['patient', 'doctor', 'reviewedBy', 'items.medicine', 'items.dispensedBy', 'items.givenBy'])
            ),
        ]);
    }

    private function authorizeView(Request $request, PharmacyRequest $pharmacyRequest): void
    {
        $user = $request->user();

        if ($user->isRole('admin', 'pharmacist', 'super_admin')) {
            return;
        }

        if ($user->isRole('doctor') && $pharmacyRequest->doctor_id === $user->id) {
            return;
        }

        if ($user->isRole('patient') && $pharmacyRequest->patient_id === $user->id) {
            return;
        }

        abort(403);
    }
}
