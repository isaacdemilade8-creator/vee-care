<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientCardResource;
use App\Models\PatientCard;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PatientCardController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = PatientCard::query()->with(['patient', 'issuer']);

        if (! $user->isRole('super_admin', 'admin', 'nurse')) {
            $query->where('patient_id', $user->id);
        } elseif ($request->integer('patient_id')) {
            $query->where('patient_id', $request->integer('patient_id'));
        }

        if ($request->string('status')->toString()) {
            $query->where('status', $request->string('status')->toString());
        }

        $query->latest();

        return PatientCardResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(PatientCard $patientCard): PatientCardResource
    {
        $patientCard->load(['patient', 'issuer']);

        return new PatientCardResource($patientCard);
    }

    public function store(Request $request, AuditService $audit): PatientCardResource
    {
        $request->validate([
            'patient_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $patientId = $request->integer('patient_id');
        $issuer = $request->user();

        $existing = PatientCard::where('patient_id', $patientId)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            abort(409, 'This patient already has an active card.');
        }

        $patient = User::findOrFail($patientId);
        abort_unless($patient->isRole('patient'), 422, 'Cards can only be issued to patients.');

        $latest = PatientCard::latest('id')->value('id') ?? 0;
        $cardNumber = 'VHC-'.str_pad((string) ($latest + 1), 6, '0', STR_PAD_LEFT);

        $card = PatientCard::create([
            'organization_id' => $issuer->organization_id,
            'patient_id' => $patientId,
            'card_number' => $cardNumber,
            'status' => 'active',
            'issued_by' => $issuer->id,
            'issued_at' => now(),
            'expires_at' => now()->addYears(2),
        ]);

        $card->load(['patient', 'issuer']);

        $audit->record($request, 'patient_card.created', $card, [
            'card_number' => $cardNumber,
            'patient_id' => $patientId,
        ]);

        return new PatientCardResource($card);
    }

    public function update(Request $request, PatientCard $patientCard, AuditService $audit): PatientCardResource
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive,expired,lost'],
            'expires_at' => ['sometimes', 'date', 'after:today'],
        ]);

        $patientCard->update($data);

        $audit->record($request, 'patient_card.updated', $patientCard, [
            'changes' => array_keys($data),
        ]);

        $patientCard->load(['patient', 'issuer']);

        return new PatientCardResource($patientCard);
    }

    public function requestCard(Request $request, AuditService $audit): PatientCardResource
    {
        $patient = $request->user();

        abort_unless($patient->isRole('patient'), 403);

        $existing = PatientCard::where('patient_id', $patient->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            abort(409, 'You already have an active card.');
        }

        $request->validate([
            'cardNumber' => ['required', 'string', 'regex:/^\d{16}$/'],
            'cardName' => ['required', 'string', 'min:3'],
            'expiry' => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
            'cvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
        ]);

        $latest = PatientCard::latest('id')->value('id') ?? 0;
        $cardNumber = 'VHC-'.str_pad((string) ($latest + 1), 6, '0', STR_PAD_LEFT);

        $card = PatientCard::create([
            'organization_id' => $patient->organization_id,
            'patient_id' => $patient->id,
            'card_number' => $cardNumber,
            'status' => 'active',
            'issued_by' => $patient->id,
            'issued_at' => now(),
            'expires_at' => now()->addYears(2),
        ]);

        $card->load(['patient', 'issuer']);

        $audit->record($request, 'patient_card.requested', $card, [
            'card_number' => $cardNumber,
        ]);

        return new PatientCardResource($card);
    }

    public function myCard(Request $request): JsonResponse
    {
        $card = PatientCard::query()
            ->with(['patient', 'issuer'])
            ->where('patient_id', $request->user()->id)
            ->latest()
            ->first();

        if (! $card) {
            return response()->json(['card' => null, 'message' => 'No card issued yet.'], 200);
        }

        return response()->json([
            'card' => new PatientCardResource($card),
            'message' => 'Card found.',
        ]);
    }

    public function registerAndRequestCard(Request $request, AuditService $audit, NotificationService $notifications): PatientCardResource
    {
        $patient = $request->user();

        abort_unless($patient->isRole('patient'), 403);

        $existing = PatientCard::where('patient_id', $patient->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            abort(409, 'You already have an active card.');
        }

        $data = $request->validate([
            'phone' => ['sometimes', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'date'],
            'allergies' => ['sometimes', 'array'],
            'allergies.*' => ['string', 'max:255'],
            'chronic_conditions' => ['sometimes', 'array'],
            'chronic_conditions.*' => ['string', 'max:255'],
            'emergency_contact' => ['sometimes', 'array'],
            'cardNumber' => ['required', 'string', 'regex:/^\d{16}$/'],
            'cardName' => ['required', 'string', 'min:3'],
            'expiry' => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
            'cvv' => ['required', 'string', 'regex:/^\d{3,4}$/'],
        ]);

        if (isset($data['phone'])) {
            $patient->phone = $data['phone'];
        }

        if (isset($data['date_of_birth'])) {
            $patient->date_of_birth = $data['date_of_birth'];
        }

        $patient->save();

        PatientProfile::updateOrCreate(
            ['user_id' => $patient->id],
            [
                'allergies' => $data['allergies'] ?? [],
                'chronic_conditions' => $data['chronic_conditions'] ?? [],
                'emergency_contact' => $data['emergency_contact'] ?? [],
            ]
        );

        $latest = PatientCard::latest('id')->value('id') ?? 0;
        $cardNumber = 'VHC-'.str_pad((string) ($latest + 1), 6, '0', STR_PAD_LEFT);

        $card = PatientCard::create([
            'organization_id' => $patient->organization_id,
            'patient_id' => $patient->id,
            'card_number' => $cardNumber,
            'status' => 'active',
            'issued_by' => $patient->id,
            'issued_at' => now(),
            'expires_at' => now()->addYears(2),
        ]);

        $card->load(['patient', 'issuer']);

        $admins = User::whereIn('role', ['admin', 'super_admin'])->get();

        foreach ($admins as $admin) {
            $notifications->send(
                $admin,
                'card.issued',
                'New membership card issued',
                "Patient {$patient->name} has been issued a new membership card ({$cardNumber}).",
                ['cardId' => $card->id, 'patientId' => $patient->id]
            );
        }

        $audit->record($request, 'patient_card.registered', $card, [
            'card_number' => $cardNumber,
        ]);

        return new PatientCardResource($card);
    }

    public function linkPhysicalCard(Request $request, AuditService $audit, NotificationService $notifications): PatientCardResource
    {
        $patient = $request->user();

        abort_unless($patient->isRole('patient'), 403);

        $existing = PatientCard::where('patient_id', $patient->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            abort(409, 'You already have an active card.');
        }

        $data = $request->validate([
            'physical_card_number' => ['required', 'string', 'max:255'],
        ]);

        $latest = PatientCard::latest('id')->value('id') ?? 0;
        $cardNumber = 'VHC-'.str_pad((string) ($latest + 1), 6, '0', STR_PAD_LEFT);

        $card = PatientCard::create([
            'organization_id' => $patient->organization_id,
            'patient_id' => $patient->id,
            'card_number' => $cardNumber,
            'status' => 'active',
            'issued_by' => $patient->id,
            'issued_at' => now(),
            'expires_at' => now()->addYears(2),
            'metadata' => ['physical_card_number' => $data['physical_card_number']],
        ]);

        $card->load(['patient', 'issuer']);

        $admins = User::whereIn('role', ['admin', 'super_admin'])->get();

        foreach ($admins as $admin) {
            $notifications->send(
                $admin,
                'card.linked',
                'Physical card linked',
                "Patient {$patient->name} has linked their physical card and received virtual card ({$cardNumber}).",
                ['cardId' => $card->id, 'patientId' => $patient->id]
            );
        }

        $audit->record($request, 'patient_card.linked', $card, [
            'card_number' => $cardNumber,
            'physical_card_number' => $data['physical_card_number'],
        ]);

        return new PatientCardResource($card);
    }
}
