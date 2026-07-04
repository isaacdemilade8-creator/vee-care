<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientCardResource;
use App\Models\PatientCard;
use App\Models\User;
use App\Services\AuditService;
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
}
