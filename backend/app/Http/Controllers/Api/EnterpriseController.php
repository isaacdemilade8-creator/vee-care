<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientProfileResource;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\MedicalRecordResource;
use App\Http\Resources\LabTestResource;
use App\Http\Resources\PrescriptionResource;
use App\Http\Resources\UrgentCareRequestResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VitalResource;
use App\Models\Appointment;
use App\Models\EnterpriseRecord;
use App\Models\Invoice;
use App\Models\LabTest;
use App\Models\MedicalRecord;
use App\Models\Medicine;
use App\Models\MedicineStockMovement;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\UrgentCareRequest;
use App\Models\User;
use App\Models\Vital;
use App\Services\AuditService;
use App\Services\Contracts\AiClinicalAssistant;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EnterpriseController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json([
            'stats' => [
                'patients' => User::where('role', 'patient')->count(),
                'staff' => User::whereNot('role', 'patient')->count(),
                'appointmentsToday' => Appointment::whereDate('scheduled_at', today())->count(),
                'revenue' => Invoice::where('status', 'paid')->sum('total'),
                'lowStock' => Medicine::whereColumn('stock', '<=', 'reorder_level')->count(),
                'pendingLabs' => LabTest::whereIn('status', ['requested', 'processing'])->count(),
            ],
            'activity' => [
                ['label' => 'Appointment queue updated', 'time' => now()->subMinutes(8)->toISOString()],
                ['label' => 'Lab result uploaded', 'time' => now()->subMinutes(21)->toISOString()],
                ['label' => 'Invoice payment received', 'time' => now()->subHour()->toISOString()],
            ],
        ]);
    }

    public function patients(Request $request)
    {
        $this->ensurePatientProfiles();

        $profiles = PatientProfile::query()
            ->with('user')
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('patient_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return PatientProfileResource::collection($profiles);
    }

    private function ensurePatientProfiles(): void
    {
        User::query()
            ->where('role', 'patient')
            ->whereDoesntHave('patientProfile')
            ->select(['id'])
            ->chunkById(100, function ($patients): void {
                foreach ($patients as $patient) {
                    PatientProfile::firstOrCreate(
                        ['user_id' => $patient->id],
                        [
                            'organization_id' => null,
                            'branch_id' => null,
                            'patient_number' => 'PAT-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT),
                            'allergies' => [],
                            'chronic_conditions' => [],
                        ],
                    );
                }
            });
    }

    public function staff(Request $request)
    {
        $staff = User::query()
            ->whereIn('role', ['admin', 'doctor', 'nurse', 'lab_technician', 'pharmacist'])
            ->when($request->string('role')->toString(), fn ($query, $role) => $query->where('role', $role))
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($staff);
    }

    public function ehr(Request $request): JsonResponse
    {
        $patientId = $request->integer('patient_id') ?: null;
        $records = EnterpriseRecord::query()
            ->with(['patient', 'doctor'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (EnterpriseRecord $record) => [
                'id' => $record->id,
                'type' => $record->type,
                'title' => $record->title,
                'body' => $record->encrypted_body ? decrypt($record->encrypted_body) : null,
                'metadata' => $record->metadata ?? [],
                'patient' => new UserResource($record->patient),
                'doctor' => $record->doctor ? new UserResource($record->doctor) : null,
                'createdAt' => $record->created_at?->toISOString(),
            ]);
        $labTests = LabTest::query()
            ->with(['patient', 'requester', 'assignee'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (LabTest $test) => (new LabTestResource($test))->resolve());
        $vitals = Vital::query()
            ->with(['patient', 'recordedBy'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest('recorded_at')
            ->limit(50)
            ->get();
        $medicalRecords = MedicalRecord::query()
            ->with(['patient', 'uploader'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest()
            ->limit(50)
            ->get();
        $prescriptions = Prescription::query()
            ->with(['patient', 'doctor'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest('issued_at')
            ->limit(50)
            ->get();
        $appointments = Appointment::query()
            ->with(['patient', 'doctor', 'prescription'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest('scheduled_at')
            ->limit(50)
            ->get();
        $triage = UrgentCareRequest::query()
            ->with(['patient', 'assignee'])
            ->when($patientId, fn ($query) => $query->where('patient_id', $patientId))
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'records' => $records,
            'labTests' => $labTests,
            'vitals' => VitalResource::collection($vitals),
            'medicalRecords' => MedicalRecordResource::collection($medicalRecords),
            'prescriptions' => PrescriptionResource::collection($prescriptions),
            'appointments' => AppointmentResource::collection($appointments),
            'triage' => UrgentCareRequestResource::collection($triage),
            'summary' => [
                'notes' => $records->count(),
                'vitals' => $vitals->count(),
                'labs' => $labTests->count(),
                'records' => $medicalRecords->count(),
                'prescriptions' => $prescriptions->count(),
                'appointments' => $appointments->count(),
            ],
        ]);
    }

    public function vitals(Request $request): AnonymousResourceCollection
    {
        $vitals = Vital::query()
            ->with(['patient', 'recordedBy'])
            ->when($request->integer('patient_id'), fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->latest('recorded_at')
            ->paginate($request->integer('per_page', 20));

        return VitalResource::collection($vitals);
    }

    public function billing(Request $request): JsonResponse
    {
        return response()->json([
            'invoices' => Invoice::latest()->limit(20)->get(),
            'summary' => [
                'paid' => Invoice::where('status', 'paid')->sum('total'),
                'outstanding' => Invoice::whereIn('status', ['draft', 'sent'])->sum('total'),
            ],
        ]);
    }

    public function labTests(Request $request): AnonymousResourceCollection
    {
        $tests = LabTest::query()
            ->with(['patient', 'requester', 'assignee'])
            ->when($request->integer('patient_id'), fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->user()->isRole('lab_technician'), function ($query) use ($request): void {
                $query->where(function ($nested) use ($request): void {
                    $nested->whereNull('assigned_to')->orWhere('assigned_to', $request->user()->id);
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return LabTestResource::collection($tests);
    }

    public function createLabTest(Request $request, AuditService $audit, NotificationService $notifications): LabTestResource
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $patient = User::findOrFail($data['patient_id']);
        abort_unless($patient->isRole('patient'), 422, 'Lab tests must belong to a patient.');

        $assignee = isset($data['assigned_to']) ? User::findOrFail($data['assigned_to']) : null;
        if ($assignee) {
            abort_unless($assignee->isRole('lab_technician'), 422, 'Lab tests can only be assigned to lab technicians.');
        }

        $test = LabTest::create([
            'organization_id' => null,
            'patient_id' => $patient->id,
            'requested_by' => $request->user()->id,
            'assigned_to' => $assignee?->id,
            'name' => $data['name'],
            'status' => 'requested',
        ])->load(['patient', 'requester', 'assignee']);

        $audit->record($request, 'lab.requested', $test);

        User::query()
            ->whereIn('role', ['lab_technician', 'admin', 'super_admin'])
            ->when($assignee, fn ($query) => $query->where('id', $assignee->id)->orWhereIn('role', ['admin', 'super_admin']))
            ->get()
            ->unique('id')
            ->each(fn (User $user) => $notifications->send(
                $user,
                'lab.requested',
                'New lab request',
                "{$request->user()->name} requested {$test->name} for {$patient->name}.",
                ['labTestId' => $test->id, 'patientId' => $patient->id],
            ));

        return new LabTestResource($test);
    }

    public function pharmacy(Request $request): JsonResponse
    {
        $medicines = Medicine::query()
            ->with(['stockMovements' => fn ($query) => $query->latest()->limit(5)])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('manufacturer', 'like', "%{$search}%")
                        ->orWhere('batch_number', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('stock_state')->toString() === 'low', fn ($query) => $query->whereColumn('stock', '<=', 'reorder_level'))
            ->when($request->string('stock_state')->toString() === 'expired', fn ($query) => $query->whereDate('expires_at', '<', today()))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'medicines' => $medicines,
            'summary' => [
                'items' => Medicine::count(),
                'active' => Medicine::where('status', 'active')->count(),
                'lowStock' => Medicine::whereColumn('stock', '<=', 'reorder_level')->count(),
                'expired' => Medicine::whereDate('expires_at', '<', today())->count(),
                'inventoryValue' => Medicine::selectRaw('COALESCE(SUM(stock * unit_price), 0) as total')->value('total'),
            ],
        ]);
    }

    public function createMedicine(Request $request, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'dosage_form' => ['nullable', 'string', 'max:120'],
            'strength' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'storage_location' => ['nullable', 'string', 'max:120'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'unit_price' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'expires_at' => ['nullable', 'date'],
        ]);

        $medicine = Medicine::create([
            'organization_id' => null,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'category' => $data['category'] ?? null,
            'dosage_form' => $data['dosage_form'] ?? null,
            'strength' => $data['strength'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'batch_number' => $data['batch_number'] ?? null,
            'storage_location' => $data['storage_location'] ?? null,
            'stock' => $data['stock'] ?? 0,
            'reorder_level' => $data['reorder_level'] ?? 10,
            'unit_price' => $data['unit_price'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        if ($medicine->stock > 0) {
            $this->recordStockMovement($request, $medicine, 'opening_stock', $medicine->stock, 0, $medicine->stock, 'Initial stock');
        }

        $audit->record($request, 'medicine.created', $medicine);
        $this->notifyAdminsOfPharmacyAction(
            $request,
            $notifications,
            'Medicine added',
            "{$request->user()->name} added {$medicine->name} to inventory.",
            $medicine,
        );

        return response()->json(['message' => 'Medicine added.', 'medicine' => $medicine], 201);
    }

    public function updateMedicine(Request $request, Medicine $medicine, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'dosage_form' => ['nullable', 'string', 'max:120'],
            'strength' => ['nullable', 'string', 'max:120'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'storage_location' => ['nullable', 'string', 'max:120'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'unit_price' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'expires_at' => ['nullable', 'date'],
        ]);

        $medicine->update($data);
        $audit->record($request, 'medicine.updated', $medicine);
        $this->notifyAdminsOfPharmacyAction(
            $request,
            $notifications,
            'Medicine updated',
            "{$request->user()->name} updated {$medicine->name}.",
            $medicine,
        );

        return response()->json(['message' => 'Medicine updated.', 'medicine' => $medicine->refresh()]);
    }

    public function deleteMedicine(Request $request, Medicine $medicine, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        $medicineName = $medicine->name;
        $medicineId = $medicine->id;
        $audit->record($request, 'medicine.deleted', $medicine, ['name' => $medicine->name]);
        $medicine->delete();
        $this->notifyAdminsOfPharmacyAction(
            $request,
            $notifications,
            'Medicine removed',
            "{$request->user()->name} removed {$medicineName} from inventory.",
            null,
            ['medicine_id' => $medicineId],
        );

        return response()->json(['message' => 'Medicine removed.']);
    }

    public function aiSummary(Request $request, AiClinicalAssistant $assistant): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'integer']]);

        return response()->json($assistant->summarizePatient((int) $data['patient_id']));
    }

    public function createEhrEntry(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:users,id'],
            'type' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $record = EnterpriseRecord::create([
            'organization_id' => null,
            'patient_id' => $data['patient_id'],
            'doctor_id' => $request->user()->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'encrypted_body' => encrypt($data['body']),
            'metadata' => ['source' => 'doctor_dashboard'],
        ]);

        $audit->record($request, 'ehr.created', $record);

        return response()->json(['message' => 'Clinical note saved.', 'record' => $record], 201);
    }

    public function recordVitals(Request $request, AuditService $audit, NotificationService $notifications): VitalResource
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:users,id'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'heart_rate' => ['nullable', 'integer', 'between:20,240'],
            'blood_pressure' => ['nullable', 'string', 'max:30'],
            'weight' => ['nullable', 'numeric', 'between:1,400'],
            'height' => ['nullable', 'numeric', 'between:30,260'],
        ]);

        $vital = Vital::create([
            ...$data,
            'organization_id' => null,
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
        ]);

        $audit->record($request, 'vitals.recorded', $vital);

        $vital->load(['patient', 'recordedBy']);

        $notifications->send(
            $vital->patient,
            'vitals.recorded',
            'Vitals recorded',
            "{$request->user()->name} recorded your latest vitals.",
            ['vitalId' => $vital->id],
        );

        return new VitalResource($vital);
    }

    public function updateLabResult(Request $request, LabTest $labTest, AuditService $audit, NotificationService $notifications): LabTestResource
    {
        $data = $request->validate([
            'status' => ['required', 'in:requested,processing,completed,flagged'],
            'result_summary' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'report' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (isset($data['assigned_to'])) {
            $assignee = User::findOrFail($data['assigned_to']);
            abort_unless($assignee->isRole('lab_technician'), 422, 'Lab tests can only be assigned to lab technicians.');
        }

        $updates = [
            'status' => $data['status'],
            'result_summary' => $data['result_summary'] ?? $labTest->result_summary,
            'assigned_to' => $data['assigned_to'] ?? ($labTest->assigned_to ?: ($request->user()->isRole('lab_technician') ? $request->user()->id : $labTest->assigned_to)),
        ];

        if ($request->hasFile('report')) {
            if ($labTest->report_path) {
                Storage::disk('public')->delete($labTest->report_path);
            }
            $updates['report_path'] = $request->file('report')->store('lab-reports', 'public');
        }

        $labTest->update($updates);
        $labTest->load(['patient', 'requester', 'assignee']);
        $audit->record($request, 'lab.result_updated', $labTest);

        $notifications->send(
            $labTest->patient,
            'lab.status_changed',
            'Lab result updated',
            "Your {$labTest->name} lab request is now {$labTest->status}.",
            ['labTestId' => $labTest->id, 'status' => $labTest->status],
        );

        if ($labTest->requester && $labTest->requester->id !== $labTest->patient_id) {
            $notifications->send(
                $labTest->requester,
                'lab.status_changed',
                'Lab result updated',
                "{$labTest->name} for {$labTest->patient->name} is now {$labTest->status}.",
                ['labTestId' => $labTest->id, 'status' => $labTest->status],
            );
        }

        return new LabTestResource($labTest);
    }

    public function adjustMedicineStock(Request $request, Medicine $medicine, AuditService $audit, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'between:-10000,10000'],
            'reason' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['restock', 'dispense', 'correction', 'waste', 'return'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $before = $medicine->stock;
        $after = $before + $data['delta'];

        if ($after < 0) {
            return response()->json(['message' => 'Stock cannot go below zero.'], 422);
        }

        $medicine->update(['stock' => $after]);
        $this->recordStockMovement(
            $request,
            $medicine,
            $data['type'] ?? ($data['delta'] >= 0 ? 'restock' : 'dispense'),
            $data['delta'],
            $before,
            $after,
            $data['reason'],
            $data['reference'] ?? null,
        );
        $audit->record($request, 'medicine.stock_adjusted', $medicine, ['delta' => $data['delta'], 'reason' => $data['reason']]);
        $this->notifyAdminsOfPharmacyAction(
            $request,
            $notifications,
            'Medicine stock adjusted',
            "{$request->user()->name} changed {$medicine->name} stock by {$data['delta']}.",
            $medicine,
            ['delta' => $data['delta'], 'reason' => $data['reason']],
        );

        return response()->json(['message' => 'Medicine stock updated.', 'medicine' => $medicine->refresh()]);
    }

    private function recordStockMovement(Request $request, Medicine $medicine, string $type, int $delta, int $before, int $after, string $reason, ?string $reference = null): void
    {
        MedicineStockMovement::create([
            'medicine_id' => $medicine->id,
            'user_id' => $request->user()?->id,
            'type' => $type,
            'delta' => $delta,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason,
            'reference' => $reference,
        ]);
    }

    private function notifyAdminsOfPharmacyAction(Request $request, NotificationService $notifications, string $title, string $body, ?Medicine $medicine = null, array $data = []): void
    {
        if (! $request->user()?->isRole('pharmacist')) {
            return;
        }

        User::query()
            ->whereIn('role', ['admin', 'super_admin'])
            ->get()
            ->each(fn (User $user) => $notifications->send(
                $user,
                'pharmacy.inventory_action',
                $title,
                $body,
                [
                    ...$data,
                    'medicine_id' => $medicine?->id ?? $data['medicine_id'] ?? null,
                    'actor_id' => $request->user()->id,
                ],
            ));
    }

    public function registerStaff(Request $request, AuditService $audit): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['doctor', 'nurse', 'lab_technician', 'pharmacist'])],
            'specialty' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $staff = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'specialty' => $data['specialty'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        $audit->record($request, 'staff.registered', $staff, [
            'role' => $staff->role,
        ]);

        return new UserResource($staff);
    }

    public function emergencyRequest(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        $audit->record($request, 'patient.emergency_requested', null, $data);

        return response()->json(['message' => 'Emergency request sent to the care desk.']);
    }
}
