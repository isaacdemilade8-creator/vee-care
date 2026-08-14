<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Inpatient admission acceptance tests.
 *
 * Admissions run inside the tenant database resolved from the request host and
 * behind `role:hospital_admin`, so every admission is scoped to one hospital
 * (another tenant's id 404s) and only hospital administrators can reach them.
 * The full structure chain (department -> ward -> room -> bed) must resolve
 * through one active chain; a bed becomes occupied on admit and returns to
 * available on discharge, with partial unique database indexes guaranteeing a
 * bed or patient can never be active twice even under a lost race.
 */
class AdmissionFeatureTest extends TestCase
{
    use CreatesTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'control']);

        $this->migrateControlDatabase();
        $this->cleanupTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->cleanupTenantDatabases();
        $this->disconnectFromTenant();

        parent::tearDown();
    }

    protected function tenantHost(Tenant $tenant): string
    {
        return "http://{$tenant->slug}.vee-care.test";
    }

    protected function login(Tenant $tenant, string $email, string $password = 'password123'): TestResponse
    {
        return $this->postJson($this->tenantHost($tenant).'/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * @return array{tenant: Tenant, host: string, token: string}
     */
    protected function provisionAndLoginAdmin(string $slug): array
    {
        $tenant = $this->provisionTenant($slug);
        $host = $this->tenantHost($tenant);

        $response = $this->login($tenant, "admin@{$slug}.vee-care.test");
        $response->assertOk();

        return ['tenant' => $tenant, 'host' => $host, 'token' => $response->json('token')];
    }

    /**
     * @return array{tenant: Tenant, host: string, token: string}
     */
    protected function provisionAndLoginDoctor(string $slug): array
    {
        $admin = $this->provisionAndLoginAdmin($slug);

        $this->withToken($admin['token'])->postJson($admin['host'].'/api/admin/users', [
            'name' => 'Doctor One',
            'email' => "doctor@{$slug}.vee-care.test",
            'password' => 'password123',
            'role' => 'doctor',
        ])->assertCreated();

        $response = $this->login($admin['tenant'], "doctor@{$slug}.vee-care.test");
        $response->assertOk();

        return ['tenant' => $admin['tenant'], 'host' => $admin['host'], 'token' => $response->json('token')];
    }

    /**
     * Provision admin, a department -> ward -> room -> bed chain, a patient and
     * a doctor. Returns everything the admission tests need.
     *
     * @return array{host: string, token: string, chain: array{department: int, ward: int, room: int, bedA: int, bedB: int}, patient: int, doctor: int}
     */
    protected function setupHospital(string $slug): array
    {
        $hospital = $this->provisionAndLoginAdmin($slug);
        $host = $hospital['host'];
        $token = $hospital['token'];

        $department = $this->withToken($token)->postJson($host.'/api/admin/departments', [
            'name' => 'Cardiology',
        ])->assertCreated();

        $ward = $this->withToken($token)->postJson($host.'/api/admin/wards', [
            'department_id' => $department->json('data.id'),
            'name' => 'Cardiology Ward',
            'capacity' => 10,
        ])->assertCreated();

        $room = $this->withToken($token)->postJson($host.'/api/admin/rooms', [
            'ward_id' => $ward->json('data.id'),
            'name' => '101',
            'capacity' => 4,
        ])->assertCreated();

        $bedA = $this->withToken($token)->postJson($host.'/api/admin/beds', [
            'room_id' => $room->json('data.id'),
            'bed_number' => '101A',
        ])->assertCreated();

        $bedB = $this->withToken($token)->postJson($host.'/api/admin/beds', [
            'room_id' => $room->json('data.id'),
            'bed_number' => '101B',
        ])->assertCreated();

        $patient = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'password' => 'password123',
            'role' => 'patient',
        ])->assertCreated();

        $doctor = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'Dr. Ada',
            'email' => 'ada@example.test',
            'password' => 'password123',
            'role' => 'doctor',
        ])->assertCreated();

        return [
            'tenant' => $hospital['tenant'],
            'host' => $host,
            'token' => $token,
            'chain' => [
                'department' => (int) $department->json('data.id'),
                'ward' => (int) $ward->json('data.id'),
                'room' => (int) $room->json('data.id'),
                'bedA' => (int) $bedA->json('data.id'),
                'bedB' => (int) $bedB->json('data.id'),
            ],
            'patient' => (int) $patient->json('data.id'),
            'doctor' => (int) $doctor->json('data.id'),
        ];
    }

    protected function admissionPayload(array $hospital, int $bedId, array $overrides = []): array
    {
        return array_merge([
            'patient_id' => $hospital['patient'],
            'department_id' => $hospital['chain']['department'],
            'ward_id' => $hospital['chain']['ward'],
            'room_id' => $hospital['chain']['room'],
            'bed_id' => $bedId,
            'reason' => 'Chest pain observation',
        ], $overrides);
    }

    public function test_admin_admits_a_patient_and_the_bed_becomes_occupied(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $response = $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], [
                'practitioner_id' => $hospital['doctor'],
                'notes' => 'Monitor vitals hourly.',
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'admitted')
            ->assertJsonPath('data.patient.name', 'Jane Doe')
            ->assertJsonPath('data.practitioner.name', 'Dr. Ada')
            ->assertJsonPath('data.department.name', 'Cardiology')
            ->assertJsonPath('data.ward.name', 'Cardiology Ward')
            ->assertJsonPath('data.room.name', '101')
            ->assertJsonPath('data.bed.bedNumber', '101A')
            ->assertJsonPath('data.reason', 'Chest pain observation')
            ->assertJsonPath('data.notes', 'Monitor vitals hourly.');

        $admissionId = (int) $response->json('data.id');

        // The bed flips to occupied and shows up as such.
        $this->withToken($token)->getJson($host."/api/admin/beds/{$hospital['chain']['bedA']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'occupied');

        // The admission list and show endpoint report the same record.
        $this->withToken($token)->getJson($host.'/api/admin/admissions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'admitted');

        $this->withToken($token)->getJson($host."/api/admin/admissions/{$admissionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $admissionId)
            ->assertJsonPath('data.bed.bedNumber', '101A');
    }

    public function test_the_structure_chain_must_be_consistent_and_active(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        // A room that exists but belongs to a different ward cannot satisfy the
        // chain: the references pass `exists` validation and fail the explicit
        // chain consistency check.
        $otherWard = $this->withToken($token)->postJson($host.'/api/admin/wards', [
            'name' => 'Other Ward',
            'capacity' => 5,
        ])->assertCreated();

        $otherRoom = $this->withToken($token)->postJson($host.'/api/admin/rooms', [
            'ward_id' => $otherWard->json('data.id'),
            'name' => '202',
            'capacity' => 2,
        ])->assertCreated();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], [
                'room_id' => (int) $otherRoom->json('data.id'),
                'ward_id' => (int) $otherWard->json('data.id'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bed_id');

        // A department reference that belongs to a different ward fails too.
        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], [
                'department_id' => $hospital['chain']['department'] + 99,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');

        // An inactive ward cannot accept admissions.
        $this->withToken($token)->patchJson($host."/api/admin/wards/{$hospital['chain']['ward']}", ['status' => 'inactive'])
            ->assertOk();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ward_id');

        $this->withToken($token)->patchJson($host."/api/admin/wards/{$hospital['chain']['ward']}", ['status' => 'active'])
            ->assertOk();

        // An out-of-service bed is refused.
        $this->withToken($token)->patchJson($host."/api/admin/beds/{$hospital['chain']['bedA']}", ['is_active' => false])
            ->assertOk();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bed_id');

        $this->withToken($token)->patchJson($host."/api/admin/beds/{$hospital['chain']['bedA']}", ['is_active' => true])
            ->assertOk();

        // An already occupied bed is refused (with a valid second patient).
        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $secondPatient = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'Second Patient',
            'email' => 'second@example.test',
            'password' => 'password123',
            'role' => 'patient',
        ])->assertCreated();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], ['patient_id' => (int) $secondPatient->json('data.id')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bed_id');
    }

    public function test_patient_and_practitioner_role_rules_are_enforced(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        // A doctor cannot be admitted as a patient.
        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], ['patient_id' => $hospital['doctor']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');

        // A patient cannot be assigned as the attending practitioner.
        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], ['practitioner_id' => $hospital['patient']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('practitioner_id');

        // An inactive patient cannot be admitted.
        $this->withToken($token)->postJson($host."/api/admin/users/{$hospital['patient']}/deactivate")
            ->assertOk();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_a_patient_cannot_have_two_active_admissions(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        // Same patient, second bed: refused at the service level.
        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedB']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_a_bed_cannot_be_double_booked(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $secondPatient = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'John Roe',
            'email' => 'john@example.test',
            'password' => 'password123',
            'role' => 'patient',
        ])->assertCreated();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], ['patient_id' => (int) $secondPatient->json('data.id')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bed_id');
    }

    public function test_database_backstop_rejects_duplicate_active_admissions(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $this->connectToTenant($hospital['tenant']);

        // Bypassing the service entirely, the partial unique index on the
        // patient (active rows only) still rejects a second active admission.
        $this->expectException(QueryException::class);

        Admission::create([
            'organization_id' => null,
            'patient_id' => $hospital['patient'],
            'department_id' => $hospital['chain']['department'],
            'ward_id' => $hospital['chain']['ward'],
            'room_id' => $hospital['chain']['room'],
            'bed_id' => $hospital['chain']['bedB'],
            'status' => Admission::STATUS_ADMITTED,
            'admitted_at' => now(),
        ]);
    }

    public function test_discharge_releases_the_bed_and_enforces_the_lifecycle(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $admission = $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $admissionId = (int) $admission->json('data.id');

        $discharge = $this->withToken($token)->postJson($host."/api/admin/admissions/{$admissionId}/discharge")
            ->assertOk()
            ->assertJsonPath('data.status', 'discharged')
            ->assertJsonPath('data.bed.status', 'available');

        $this->assertNotNull($discharge->json('data.dischargedAt'));
        $this->assertNotNull($discharge->json('data.admittedAt'));

        // The bed is available again and the admission history is retained.
        $this->withToken($token)->getJson($host."/api/admin/beds/{$hospital['chain']['bedA']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'available');

        $this->withToken($token)->getJson($host.'/api/admin/admissions?status=discharged')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // A discharged admission cannot be discharged again.
        $this->withToken($token)->postJson($host."/api/admin/admissions/{$admissionId}/discharge")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        // Updating an admission only touches metadata; the bed cannot move
        // (transfers are intentionally out of scope) and the status cannot be
        // rolled back.
        $this->withToken($token)->patchJson($host."/api/admin/admissions/{$admissionId}", [
            'bed_id' => $hospital['chain']['bedB'],
            'status' => 'admitted',
            'notes' => 'Follow-up scheduled.',
        ])->assertOk()
            ->assertJsonPath('data.bed.bedNumber', '101A')
            ->assertJsonPath('data.status', 'discharged')
            ->assertJsonPath('data.notes', 'Follow-up scheduled.');

        // The same patient can be re-admitted (a fresh history record).
        $readmitted = $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'admitted');

        $this->assertNotEquals($admissionId, (int) $readmitted->json('data.id'));

        $this->withToken($token)->getJson($host.'/api/admin/admissions')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_deleting_an_active_admission_releases_the_bed(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $admission = $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $admissionId = (int) $admission->json('data.id');

        $this->withToken($token)->deleteJson($host."/api/admin/admissions/{$admissionId}")
            ->assertOk()
            ->assertJsonPath('message', 'Admission deleted.');

        $this->withToken($token)->getJson($host."/api/admin/admissions/{$admissionId}")
            ->assertNotFound();

        $this->withToken($token)->getJson($host."/api/admin/beds/{$hospital['chain']['bedA']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'available');
    }

    public function test_admissions_are_scoped_to_each_hospital(): void
    {
        $hospitalOne = $this->setupHospital('hospital-one');
        $hospitalTwo = $this->setupHospital('hospital-two');

        $admission = $this->withToken($hospitalOne['token'])->postJson($hospitalOne['host'].'/api/admin/admissions',
            $this->admissionPayload($hospitalOne, $hospitalOne['chain']['bedA']))
            ->assertCreated();

        $admissionId = (int) $admission->json('data.id');

        // Hospital Two never sees Hospital One's admissions.
        $this->withToken($hospitalTwo['token'])->getJson($hospitalTwo['host'].'/api/admin/admissions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Cross-tenant read, discharge and delete all 404 (route model binding
        // resolves inside Hospital Two's empty database).
        $this->withToken($hospitalTwo['token'])->getJson("{$hospitalTwo['host']}/api/admin/admissions/{$admissionId}")
            ->assertNotFound();
        $this->withToken($hospitalTwo['token'])->patchJson("{$hospitalTwo['host']}/api/admin/admissions/{$admissionId}", ['notes' => 'hi'])
            ->assertNotFound();
        $this->withToken($hospitalTwo['token'])->postJson("{$hospitalTwo['host']}/api/admin/admissions/{$admissionId}/discharge")
            ->assertNotFound();
        $this->withToken($hospitalTwo['token'])->deleteJson("{$hospitalTwo['host']}/api/admin/admissions/{$admissionId}")
            ->assertNotFound();

        // A patient reference from Hospital One does not exist in Hospital Two.
        // Extra user in Hospital One guarantees an id Hospital Two cannot have.
        $foreignUser = $this->withToken($hospitalOne['token'])->postJson($hospitalOne['host'].'/api/admin/users', [
            'name' => 'Extra User',
            'email' => 'extra@example.test',
            'password' => 'password123',
            'role' => 'nurse',
        ])->assertCreated();

        $this->withToken($hospitalTwo['token'])->postJson($hospitalTwo['host'].'/api/admin/admissions',
            $this->admissionPayload($hospitalTwo, $hospitalTwo['chain']['bedA'], [
                'patient_id' => (int) $foreignUser->json('data.id'),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_non_admin_users_are_forbidden_from_admission_endpoints(): void
    {
        $doctor = $this->provisionAndLoginDoctor('hospital-one');
        $host = $doctor['host'];

        $this->withToken($doctor['token'])->getJson($host.'/api/admin/admissions')
            ->assertForbidden();
        $this->withToken($doctor['token'])->postJson($host.'/api/admin/admissions', [])
            ->assertForbidden();
        $this->withToken($doctor['token'])->getJson($host.'/api/admin/beds/availability')
            ->assertForbidden();
        $this->withToken($doctor['token'])->getJson($host.'/api/admin/occupancy')
            ->assertForbidden();
    }

    public function test_availability_lists_only_assignable_beds(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        // Occupy bedA; deactivate... keep bedB available.
        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $this->withToken($token)->getJson($host.'/api/admin/beds/availability')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bedNumber', '101B');

        // Filtering by room and ward narrows the same result.
        $this->withToken($token)->getJson($host."/api/admin/beds/availability?room_id={$hospital['chain']['room']}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // An unavailable bed is excluded.
        $this->withToken($token)->patchJson($host."/api/admin/beds/{$hospital['chain']['bedB']}", ['status' => 'reserved'])
            ->assertOk();

        $this->withToken($token)->getJson($host.'/api/admin/beds/availability')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_occupancy_summary_reflects_bed_state(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA']))
            ->assertCreated();

        $this->withToken($token)->getJson($host."/api/admin/occupancy?ward_id={$hospital['chain']['ward']}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cardiology Ward')
            ->assertJsonPath('data.0.totalBeds', 2)
            ->assertJsonPath('data.0.occupiedBeds', 1)
            ->assertJsonPath('data.0.availableBeds', 1)
            ->assertJsonPath('data.0.reservedBeds', 0);

        // After discharge the occupancy counts move back.
        $admissionId = (int) $this->withToken($token)->getJson($host.'/api/admin/admissions?status=admitted')
            ->assertOk()
            ->json('data.0.id');

        $this->withToken($token)->postJson($host."/api/admin/admissions/{$admissionId}/discharge")
            ->assertOk();

        $this->withToken($token)->getJson($host."/api/admin/occupancy?ward_id={$hospital['chain']['ward']}")
            ->assertOk()
            ->assertJsonPath('data.0.occupiedBeds', 0)
            ->assertJsonPath('data.0.availableBeds', 2);
    }

    public function test_index_supports_search_status_and_date_filters(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $admission = $this->withToken($token)->postJson($host.'/api/admin/admissions',
            $this->admissionPayload($hospital, $hospital['chain']['bedA'], ['admitted_at' => now()->subDays(2)->toDateTimeString()]))
            ->assertCreated();

        $admissionId = (int) $admission->json('data.id');

        $this->withToken($token)->postJson($host."/api/admin/admissions/{$admissionId}/discharge")
            ->assertOk();

        // Search by patient name and by patient number (PAT-%06d of the user id).
        $this->withToken($token)->getJson($host.'/api/admin/admissions?search=jane')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $patientNumber = 'PAT-'.str_pad((string) $hospital['patient'], 6, '0', STR_PAD_LEFT);

        $this->withToken($token)->getJson($host."/api/admin/admissions?search={$patientNumber}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Status filter isolates discharged history.
        $this->withToken($token)->getJson($host.'/api/admin/admissions?status=discharged')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)->getJson($host.'/api/admin/admissions?status=admitted')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Date filters use admitted_at.
        $this->withToken($token)->getJson($host.'/api/admin/admissions?admitted_after='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($token)->getJson($host.'/api/admin/admissions?admitted_before='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
