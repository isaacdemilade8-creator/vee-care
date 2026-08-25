<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\DutyService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Practitioner duty & shift acceptance tests.
 *
 * Shifts and duty assignments run inside the tenant database resolved from the
 * request host and behind `role:hospital_admin`. A shift is a reusable
 * working-period definition (midnight-crossing windows are valid); a duty
 * assignment records who works which shift on which date. One practitioner can
 * never hold two overlapping duties — enforced by DutyService under the
 * practitioner's row lock — while several practitioners may share one shift.
 * "Currently on duty" is derived from duty_date + shift window + status in the
 * hospital's configured timezone; history is never rewritten (completed and
 * cancelled assignments are kept and cannot be deleted).
 */
class DutyShiftFeatureTest extends TestCase
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
        Carbon::setTestNow();

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

    protected function loginDoctor(array $hospital): string
    {
        $response = $this->login($hospital['tenant'], 'ada@example.test');
        $response->assertOk();

        return $response->json('token');
    }

    /**
     * Provision an admin, a department -> ward chain, Morning & Night shifts
     * and three practitioners (doctor, nurse, pharmacist).
     *
     * @return array{tenant: Tenant, host: string, token: string, department: int, ward: int, shiftMorning: int, shiftNight: int, doctor: int, nurse: int, pharmacist: int}
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

        $morning = $this->withToken($token)->postJson($host.'/api/admin/shifts', [
            'name' => 'Morning',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ])->assertCreated();

        $night = $this->withToken($token)->postJson($host.'/api/admin/shifts', [
            'name' => 'Night',
            'start_time' => '22:00',
            'end_time' => '06:00',
        ])->assertCreated();

        $doctor = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'Dr. Ada',
            'email' => 'ada@example.test',
            'password' => 'password123',
            'role' => 'doctor',
        ])->assertCreated();

        $nurse = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'Nurse Bea',
            'email' => 'bea@example.test',
            'password' => 'password123',
            'role' => 'nurse',
        ])->assertCreated();

        $pharmacist = $this->withToken($token)->postJson($host.'/api/admin/users', [
            'name' => 'Pharm Cy',
            'email' => 'cy@example.test',
            'password' => 'password123',
            'role' => 'pharmacist',
        ])->assertCreated();

        return [
            'tenant' => $hospital['tenant'],
            'host' => $host,
            'token' => $token,
            'department' => (int) $department->json('data.id'),
            'ward' => (int) $ward->json('data.id'),
            'shiftMorning' => (int) $morning->json('data.id'),
            'shiftNight' => (int) $night->json('data.id'),
            'doctor' => (int) $doctor->json('data.id'),
            'nurse' => (int) $nurse->json('data.id'),
            'pharmacist' => (int) $pharmacist->json('data.id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dutyPayload(array $hospital, array $overrides = []): array
    {
        return array_merge([
            'practitioner_id' => $hospital['doctor'],
            'shift_id' => $hospital['shiftMorning'],
            'department_id' => $hospital['department'],
            'ward_id' => $hospital['ward'],
            'duty_date' => '2026-08-20',
        ], $overrides);
    }

    protected function assign(array $hospital, array $overrides = []): TestResponse
    {
        return $this->withToken($hospital['token'])
            ->postJson($hospital['host'].'/api/admin/duties', $this->dutyPayload($hospital, $overrides));
    }

    protected function freeze(string $datetime): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', $datetime, 'UTC'));
    }

    /*
    |--------------------------------------------------------------------------
    | Shift definition management
    |--------------------------------------------------------------------------
    */

    public function test_hospital_admin_can_create_and_list_shifts(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        // Morning/Night were created in setup; add one more through the API.
        $response = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Evening',
            'start_time' => '16:00',
            'end_time' => '22:00',
            'description' => 'Late shift',
        ]);
        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Evening');
        $response->assertJsonPath('data.startTime', '16:00');
        $response->assertJsonPath('data.endTime', '22:00');
        $response->assertJsonPath('data.status', 'active');

        $list = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/shifts');
        $list->assertOk();
        $list->assertJsonCount(3, 'data');
        $this->assertSame(
            ['Evening', 'Morning', 'Night'],
            collect($list->json('data'))->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_hospital_admin_can_update_a_shift(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $response = $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/shifts/{$hospital['shiftMorning']}",
            ['name' => 'Early Morning', 'start_time' => '07:00', 'end_time' => '15:00'],
        );
        $response->assertOk();
        $response->assertJsonPath('data.name', 'Early Morning');
        $response->assertJsonPath('data.startTime', '07:00');
        $response->assertJsonPath('data.endTime', '15:00');

        $show = $this->withToken($hospital['token'])->getJson(
            $hospital['host']."/api/admin/shifts/{$hospital['shiftMorning']}",
        );
        $show->assertOk();
        $show->assertJsonPath('data.name', 'Early Morning');
    }

    public function test_hospital_admin_can_deactivate_a_shift_and_filter_by_status(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $response = $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/shifts/{$hospital['shiftNight']}",
            ['status' => 'inactive'],
        );
        $response->assertOk();
        $response->assertJsonPath('data.status', 'inactive');

        $active = $this->withToken($hospital['token'])
            ->getJson($hospital['host'].'/api/admin/shifts?status=active');
        $active->assertOk();
        $active->assertJsonCount(1, 'data');
        $active->assertJsonPath('data.0.name', 'Morning');

        $inactive = $this->withToken($hospital['token'])
            ->getJson($hospital['host'].'/api/admin/shifts?status=inactive');
        $inactive->assertOk();
        $inactive->assertJsonCount(1, 'data');
        $inactive->assertJsonPath('data.0.name', 'Night');
    }

    public function test_shift_with_equal_start_and_end_times_is_rejected(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $response = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Ambiguous',
            'start_time' => '08:00',
            'end_time' => '08:00',
        ]);
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['end_time']);
    }

    public function test_midnight_crossing_shift_is_valid(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        // Night (22:00 -> 06:00) already exists from setup; assert its shape.
        $show = $this->withToken($hospital['token'])->getJson(
            $hospital['host']."/api/admin/shifts/{$hospital['shiftNight']}",
        );
        $show->assertOk();
        $show->assertJsonPath('data.startTime', '22:00');
        $show->assertJsonPath('data.endTime', '06:00');
        $show->assertJsonPath('data.status', 'active');
    }

    public function test_shift_names_must_be_unique_within_each_hospital(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $duplicate = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Morning',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
        $duplicate->assertUnprocessable();
        $duplicate->assertJsonValidationErrors(['name']);

        // Another hospital is a separate database: same name is fine there.
        $other = $this->provisionAndLoginAdmin('hospital-two');
        $response = $this->withToken($other['token'])->postJson($other['host'].'/api/admin/shifts', [
            'name' => 'Morning',
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);
        $response->assertCreated();
    }

    public function test_unused_shifts_can_be_deleted_but_shifts_with_assignments_cannot(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $unused = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Evening',
            'start_time' => '16:00',
            'end_time' => '22:00',
        ])->assertCreated();

        $delete = $this->withToken($hospital['token'])->deleteJson(
            $hospital['host'].'/api/admin/shifts/'.$unused->json('data.id'),
        );
        $delete->assertOk();
        $delete->assertJsonPath('message', 'Shift deleted.');

        $this->assign($hospital)->assertCreated();

        $blocked = $this->withToken($hospital['token'])->deleteJson(
            $hospital['host']."/api/admin/shifts/{$hospital['shiftMorning']}",
        );
        $blocked->assertUnprocessable();
        $blocked->assertJsonValidationErrors(['shift_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Duty assignment management
    |--------------------------------------------------------------------------
    */

    public function test_hospital_admin_can_assign_and_retrieve_duties(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $created = $this->assign($hospital, ['notes' => 'Bring charts']);
        $created->assertCreated();
        $created->assertJsonPath('data.status', 'scheduled');
        $created->assertJsonPath('data.dutyDate', '2026-08-20');
        $created->assertJsonPath('data.practitioner.name', 'Dr. Ada');
        $created->assertJsonPath('data.practitioner.role', 'doctor');
        $created->assertJsonPath('data.shift.name', 'Morning');
        $created->assertJsonPath('data.department.name', 'Cardiology');
        $created->assertJsonPath('data.ward.name', 'Cardiology Ward');
        $created->assertJsonPath('data.notes', 'Bring charts');

        $id = $created->json('data.id');

        $show = $this->withToken($hospital['token'])->getJson($hospital['host']."/api/admin/duties/{$id}");
        $show->assertOk();
        $show->assertJsonPath('data.id', $id);
        $show->assertJsonPath('data.status', 'scheduled');
    }

    public function test_duty_list_supports_filters(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $this->assign($hospital)->assertCreated();                                        // doctor / Morning / 2026-08-20
        $this->assign($hospital, ['practitioner_id' => $hospital['nurse'], 'duty_date' => '2026-08-21'])->assertCreated();

        $all = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties');
        $all->assertOk();
        $all->assertJsonCount(2, 'data');

        $byPractitioner = $this->withToken($hospital['token'])
            ->getJson($hospital['host']."/api/admin/duties?practitioner_id={$hospital['nurse']}");
        $byPractitioner->assertOk();
        $byPractitioner->assertJsonCount(1, 'data');
        $byPractitioner->assertJsonPath('data.0.practitioner.name', 'Nurse Bea');

        $byDate = $this->withToken($hospital['token'])
            ->getJson($hospital['host'].'/api/admin/duties?date=2026-08-20');
        $byDate->assertOk();
        $byDate->assertJsonCount(1, 'data');
        $byDate->assertJsonPath('data.0.dutyDate', '2026-08-20');

        $bySearch = $this->withToken($hospital['token'])
            ->getJson($hospital['host'].'/api/admin/duties?search=Ada');
        $bySearch->assertOk();
        $bySearch->assertJsonCount(1, 'data');

        $byRange = $this->withToken($hospital['token'])
            ->getJson($hospital['host'].'/api/admin/duties?from=2026-08-21&to=2026-08-21');
        $byRange->assertOk();
        $byRange->assertJsonCount(1, 'data');
    }

    public function test_an_assignment_can_be_moved_with_conflict_rechecking(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $first = $this->assign($hospital)->assertCreated();
        $second = $this->assign($hospital, ['duty_date' => '2026-08-21'])->assertCreated();

        // Notes-only edits skip the overlap machinery entirely.
        $notes = $this->withToken($hospital['token'])->patchJson(
            $hospital['host'].'/api/admin/duties/'.$first->json('data.id'),
            ['notes' => 'Updated notes'],
        );
        $notes->assertOk();
        $notes->assertJsonPath('data.notes', 'Updated notes');

        // Moving onto an occupied slot for the same practitioner is rejected.
        $conflict = $this->withToken($hospital['token'])->patchJson(
            $hospital['host'].'/api/admin/duties/'.$second->json('data.id'),
            ['duty_date' => '2026-08-20'],
        );
        $conflict->assertUnprocessable();
        $conflict->assertJsonValidationErrors(['shift_id']);
    }

    public function test_an_assignment_can_be_cancelled_and_cancelled_duties_cannot_be_deleted(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $dutyId = $this->assign($hospital)->json('data.id');

        $cancelled = $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$dutyId}",
            ['status' => 'cancelled'],
        );
        $cancelled->assertOk();
        $cancelled->assertJsonPath('data.status', 'cancelled');

        // History is kept: still listed, filterable, but not deletable.
        $history = $this->withToken($hospital['token'])
            ->getJson($hospital['host'].'/api/admin/duties?status=cancelled');
        $history->assertOk();
        $history->assertJsonCount(1, 'data');

        $delete = $this->withToken($hospital['token'])->deleteJson($hospital['host']."/api/admin/duties/{$dutyId}");
        $delete->assertUnprocessable();
        $delete->assertJsonValidationErrors(['status']);

        // And cancellation frees the slot for a new assignment.
        $replacement = $this->assign($hospital, ['practitioner_id' => $hospital['nurse']]);
        $replacement->assertCreated();
    }

    public function test_completed_requires_the_window_to_have_ended(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $this->freeze('2026-08-20 09:00:00'); // inside Morning 08:00-16:00 on 2026-08-20

        $dutyId = $this->assign($hospital)->json('data.id');

        $premature = $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$dutyId}",
            ['status' => 'completed'],
        );
        $premature->assertUnprocessable();
        $premature->assertJsonValidationErrors(['status']);

        $this->freeze('2026-08-20 16:30:00');

        $done = $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$dutyId}",
            ['status' => 'completed'],
        );
        $done->assertOk();
        $done->assertJsonPath('data.status', 'completed');
    }

    public function test_only_future_scheduled_duties_can_be_deleted(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $this->freeze('2026-08-19 12:00:00'); // before the 2026-08-20 window

        $future = $this->assign($hospital)->assertCreated(); // window ends 2026-08-20 16:00 UTC

        // Before the shift has ended: deletable.
        $deleted = $this->withToken($hospital['token'])->deleteJson(
            $hospital['host'].'/api/admin/duties/'.$future->json('data.id'),
        );
        $deleted->assertOk();

        $recreated = $this->assign($hospital)->assertCreated();
        $this->freeze('2026-08-20 17:00:00');

        // After the shift has ended: kept for history, cancel instead.
        $kept = $this->withToken($hospital['token'])->deleteJson(
            $hospital['host'].'/api/admin/duties/'.$recreated->json('data.id'),
        );
        $kept->assertUnprocessable();
        $kept->assertJsonValidationErrors(['status']);
    }

    public function test_cancelled_assignments_cannot_become_active_again(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $dutyId = $this->assign($hospital)->json('data.id');

        $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$dutyId}",
            ['status' => 'cancelled'],
        )->assertOk();

        $revive = $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$dutyId}",
            ['status' => 'scheduled'],
        );
        $revive->assertUnprocessable();
        $revive->assertJsonValidationErrors(['status']);
    }

    public function test_deactivated_shift_keeps_assignments_but_rejects_new_ones(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $dutyId = $this->assign($hospital)->json('data.id');

        $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/shifts/{$hospital['shiftMorning']}",
            ['status' => 'inactive'],
        )->assertOk();

        $existing = $this->withToken($hospital['token'])->getJson($hospital['host']."/api/admin/duties/{$dutyId}");
        $existing->assertOk();
        $existing->assertJsonPath('data.status', 'scheduled');

        $newAssignment = $this->assign($hospital, ['practitioner_id' => $hospital['nurse']]);
        $newAssignment->assertUnprocessable();
        $newAssignment->assertJsonValidationErrors(['shift_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Overlap conflicts
    |--------------------------------------------------------------------------
    */

    public function test_overlapping_practitioner_shifts_are_rejected(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $extended = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Extended Day',
            'start_time' => '14:00',
            'end_time' => '22:00',
        ])->assertCreated();

        $this->assign($hospital)->assertCreated();

        $overlap = $this->assign($hospital, ['shift_id' => $extended->json('data.id')]);
        $overlap->assertUnprocessable();
        $overlap->assertJsonValidationErrors(['shift_id']);
    }

    public function test_non_overlapping_shifts_are_accepted_for_one_practitioner(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $evening = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Evening',
            'start_time' => '16:30',
            'end_time' => '21:00',
        ])->assertCreated();

        $morning = $this->assign($hospital);
        $morning->assertCreated();

        $sameDaySecondShift = $this->assign($hospital, ['shift_id' => $evening->json('data.id')]);
        $sameDaySecondShift->assertCreated();
    }

    public function test_overnight_overlap_spans_midnight_correctly(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $earlyBird = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Early Bird',
            'start_time' => '04:00',
            'end_time' => '12:00',
        ])->assertCreated();

        $this->assign($hospital, ['shift_id' => $hospital['shiftNight'], 'duty_date' => '2026-08-20'])->assertCreated();

        // Night on the 20th runs into the morning of the 21st: conflicts there...
        $nextDay = $this->assign($hospital, [
            'shift_id' => $earlyBird->json('data.id'),
            'duty_date' => '2026-08-21',
        ]);
        $nextDay->assertUnprocessable();
        $nextDay->assertJsonValidationErrors(['shift_id']);

        // ...but not on the night's own start date.
        $sameDay = $this->assign($hospital, [
            'shift_id' => $earlyBird->json('data.id'),
        ]);
        $sameDay->assertCreated();

        // A second night on the 21st clears Early Bird entirely.
        $night = $this->assign($hospital, ['shift_id' => $hospital['shiftNight'], 'duty_date' => '2026-08-21']);
        $night->assertCreated();
    }

    public function test_multiple_practitioners_can_share_a_shift(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        $this->assign($hospital)->assertCreated();
        $this->assign($hospital, ['practitioner_id' => $hospital['nurse']])->assertCreated();
        $this->assign($hospital, ['practitioner_id' => $hospital['pharmacist']])->assertCreated();

        $list = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties');
        $list->assertOk();
        $list->assertJsonCount(3, 'data');
    }

    /**
     * Regression test for the concurrency guard: DutyService::assign performs
     * the overlap check under the practitioner's row lock inside the same
     * transaction as the insert, so a second assignment that races the first
     * observes the committed row and is rejected. SQLite cannot run parallel
     * writers in tests, so this exercises the exact code path (service-level
     * guard, not just HTTP validation) deterministically instead.
     */
    public function test_double_booking_is_rejected_by_the_service_guard(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $this->connectToTenant($hospital['tenant']);

        $service = app(DutyService::class);
        $payload = $this->dutyPayload($hospital);

        $service->assign($payload);

        try {
            $service->assign($payload);
            $this->fail('Expected double booking to be rejected.');
        } catch (ValidationException $exception) {
            $this->arrayHasKey('shift_id', $exception->errors());
        }
    }

    public function test_window_math_handles_midnight_and_boundaries(): void
    {
        // A night shift dated the 20th occupies [20th 22:00, 21st 06:00).
        $this->assertTrue(DutyService::overlaps('2026-08-20', '22:00', '06:00', '2026-08-21', '04:00', '12:00'));
        $this->assertFalse(DutyService::overlaps('2026-08-20', '22:00', '06:00', '2026-08-20', '04:00', '12:00'));

        // Same-day windows: [08:00,16:00) vs [14:00,22:00) collide...
        $this->assertTrue(DutyService::overlaps('2026-08-20', '08:00', '16:00', '2026-08-20', '14:00', '22:00'));
        // ...while touching windows ([08:00,16:00) vs [16:00,22:00)) do not.
        $this->assertFalse(DutyService::overlaps('2026-08-20', '08:00', '16:00', '2026-08-20', '16:00', '22:00'));

        // Back-to-back night shifts on consecutive dates never conflict.
        $this->assertFalse(DutyService::overlaps('2026-08-20', '22:00', '06:00', '2026-08-21', '22:00', '06:00'));

        // Identical duties always conflict.
        $this->assertTrue(DutyService::overlaps('2026-08-20', '08:00', '16:00', '2026-08-20', '08:00', '16:00'));
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant isolation & authorization
    |--------------------------------------------------------------------------
    */

    /**
     * Every id in an assignment payload resolves inside the request's own
     * tenant database (a foreign hospital's row is unreachable by id), so
     * cross-tenant isolation rests on connection isolation plus route-model
     * binding (see test_duties_are_scoped_to_each_hospital). What must be
     * rejected are references that are invalid *within* the hospital: users
     * who are not clinical staff and wards outside the assigned department.
     */
    public function test_invalid_references_are_rejected_when_assigning_duties(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        // Patients are not practitioners.
        $patient = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'password' => 'password123',
            'role' => 'patient',
        ])->assertCreated();

        $notStaff = $this->assign($hospital, ['practitioner_id' => $patient->json('data.id')]);
        $notStaff->assertUnprocessable();
        $notStaff->assertJsonValidationErrors(['practitioner_id']);

        // A ward from another department cannot be mixed into a duty.
        $neurology = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/departments', [
            'name' => 'Neurology',
        ])->assertCreated();
        $neuroWard = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/wards', [
            'department_id' => $neurology->json('data.id'),
            'name' => 'Neuro Ward',
            'capacity' => 5,
        ])->assertCreated();

        $mismatched = $this->assign($hospital, ['ward_id' => $neuroWard->json('data.id')]);
        $mismatched->assertUnprocessable();
        $mismatched->assertJsonValidationErrors(['ward_id']);

        // The matching combination still goes through.
        $this->assign($hospital, [
            'department_id' => $neurology->json('data.id'),
            'ward_id' => $neuroWard->json('data.id'),
        ])->assertCreated();
    }

    public function test_duties_are_scoped_to_each_hospital(): void
    {
        $hospitalOne = $this->setupHospital('hospital-one');
        $dutyId = $this->assign($hospitalOne)->json('data.id');

        $otherAdmin = $this->provisionAndLoginAdmin('hospital-two');

        $emptyList = $this->withToken($otherAdmin['token'])->getJson($otherAdmin['host'].'/api/admin/duties');
        $emptyList->assertOk();
        $emptyList->assertJsonCount(0, 'data');

        $emptyShifts = $this->withToken($otherAdmin['token'])->getJson($otherAdmin['host'].'/api/admin/shifts');
        $emptyShifts->assertOk();
        $emptyShifts->assertJsonCount(0, 'data');

        foreach (['get', 'patch', 'delete'] as $method) {
            $crossTenant = $this->withToken($otherAdmin['token'])->{$method.'Json'}(
                $otherAdmin['host']."/api/admin/duties/{$dutyId}",
                $method === 'patch' ? ['notes' => 'hijack'] : [],
            );
            $crossTenant->assertNotFound();
        }
    }

    public function test_non_admin_roles_receive_403_on_shift_and_duty_endpoints(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $doctorToken = $this->loginDoctor($hospital);

        $routes = [
            ['get', '/api/admin/shifts'],
            ['post', '/api/admin/shifts'],
            ['get', "/api/admin/shifts/{$hospital['shiftMorning']}"],
            ['patch', "/api/admin/shifts/{$hospital['shiftMorning']}"],
            ['delete', "/api/admin/shifts/{$hospital['shiftMorning']}"],
            ['get', '/api/admin/duties'],
            ['post', '/api/admin/duties'],
            ['get', '/api/admin/duties/current'],
        ];

        foreach ($routes as [$method, $path]) {
            $body = match ($method) {
                'post' => $this->dutyPayload($hospital),
                'patch' => ['name' => 'Nope'],
                default => [],
            };

            $response = $this->withToken($doctorToken)->{$method.'Json'}($hospital['host'].$path, $body);
            $response->assertForbidden();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Current-duty lookup ("who is on duty right now")
    |--------------------------------------------------------------------------
    */

    public function test_current_duty_returns_the_team_working_right_now_grouped_by_ward(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $this->freeze('2026-08-16 09:30:00'); // inside Morning 08:00-16:00 (UTC)

        $this->assign($hospital, ['duty_date' => '2026-08-16'])->assertCreated();
        $this->assign($hospital, ['practitioner_id' => $hospital['nurse'], 'duty_date' => '2026-08-16'])->assertCreated();

        $response = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties/current');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        $group = $response->json('data.0');
        $this->assertSame('Cardiology', $group['department']['name']);
        $this->assertSame('Cardiology Ward', $group['ward']['name']);
        $this->assertSame('Morning', $group['shift']['name']);
        $this->assertSame('08:00', $group['shift']['startTime']);

        $roles = collect($group['practitioners'])->pluck('role')->sort()->values()->all();
        $this->assertSame(['doctor', 'nurse'], $roles);

        $this->assertSame('UTC', $response->json('meta.timezone'));
        $this->assertSame('2026-08-16', $response->json('meta.date'));
    }

    public function test_current_duty_excludes_future_cancelled_and_completed_assignments(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $this->freeze('2026-08-16 09:30:00');

        // Tomorrow: not current yet.
        $this->assign($hospital, ['duty_date' => '2026-08-17'])->assertCreated();

        $future = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties/current');
        $future->assertOk();
        $future->assertJsonCount(0, 'data');

        // Cancelled today: never current.
        $cancelledId = $this->assign($hospital, ['duty_date' => '2026-08-16'])->json('data.id');
        $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$cancelledId}",
            ['status' => 'cancelled'],
        )->assertOk();

        $afterCancel = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties/current');
        $afterCancel->assertOk();
        $afterCancel->assertJsonCount(0, 'data');

        // Completed today (frozen after the shift ended): not current either.
        $this->freeze('2026-08-16 17:00:00');
        $completedId = $this->assign($hospital, ['duty_date' => '2026-08-16'])->json('data.id');
        $this->withToken($hospital['token'])->patchJson(
            $hospital['host']."/api/admin/duties/{$completedId}",
            ['status' => 'completed'],
        )->assertOk();

        $afterComplete = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties/current');
        $afterComplete->assertOk();
        $afterComplete->assertJsonCount(0, 'data');
    }

    public function test_overnight_shift_from_yesterday_is_still_current(): void
    {
        $hospital = $this->setupHospital('hospital-one');
        $this->freeze('2026-08-16 02:00:00'); // inside Night dated the 15th (22:00 -> 06:00)

        $this->assign($hospital, ['shift_id' => $hospital['shiftNight'], 'duty_date' => '2026-08-15'])->assertCreated();

        $response = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties/current');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.shift.name', 'Night');
        $response->assertJsonPath('data.0.practitioners.0.name', 'Dr. Ada');
    }

    public function test_current_duty_respects_the_configured_timezone(): void
    {
        $hospital = $this->setupHospital('hospital-one');

        // Hospital runs on Lagos time (UTC+1).
        $hospital['tenant']->update(['settings' => ['timezone' => 'Africa/Lagos']]);

        $lateMorning = $this->withToken($hospital['token'])->postJson($hospital['host'].'/api/admin/shifts', [
            'name' => 'Late Morning',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->assertCreated();

        // 08:30 UTC == 09:30 Lagos: outside the window when read naively in
        // UTC, inside it once the configured timezone is applied.
        $this->freeze('2026-08-16 08:30:00');
        $this->assign($hospital, ['shift_id' => $lateMorning->json('data.id'), 'duty_date' => '2026-08-16'])->assertCreated();

        $response = $this->withToken($hospital['token'])->getJson($hospital['host'].'/api/admin/duties/current');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.timezone', 'Africa/Lagos');
        $response->assertJsonPath('data.0.shift.name', 'Late Morning');
    }
}
