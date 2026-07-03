<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MedicalRecordController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = MedicalRecord::query()->with(['patient', 'uploader'])->latest();

        if ($user->isRole('patient')) {
            $query->where('patient_id', $user->id);
        } elseif ($user->isRole('doctor')) {
            $query->whereHas('patient.patientAppointments', fn ($q) => $q->where('doctor_id', $user->id));
        }

        $query->when($request->string('search')->toString(), function ($q, $search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhereHas('patient', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
        });

        return MedicalRecordResource::collection($query->paginate($request->integer('per_page', 10)));
    }

    public function store(Request $request, AuditService $audit): MedicalRecordResource
    {
        $user = $request->user();
        $data = $request->validate([
            'patient_id' => ['nullable', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $patientId = $user->isRole('patient') ? $user->id : (int) ($data['patient_id'] ?? 0);
        $patient = User::findOrFail($patientId);
        abort_unless($patient->isRole('patient'), 422, 'Medical records must belong to a patient.');

        if ($user->isRole('doctor')) {
            $hasRelationship = $patient->patientAppointments()->where('doctor_id', $user->id)->exists();
            abort_unless($hasRelationship, 403, 'Doctors can only upload records for their patients.');
        }

        $path = $request->file('file')->store('medical-records', 'public');

        $record = MedicalRecord::create([
            'organization_id' => null,
            'patient_id' => $patient->id,
            'uploaded_by' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $path,
            'file_type' => $request->file('file')->getMimeType(),
        ]);

        $audit->record($request, 'medical_record.created', $record);

        return new MedicalRecordResource($record->load(['patient', 'uploader']));
    }
}
