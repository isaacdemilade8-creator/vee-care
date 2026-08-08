<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\PatientProfile;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Core-domain isolation tests.
 *
 * Verifies that the hospital boundary is enforced by the resolved tenant
 * database rather than by any client-supplied organization/hospital id: users,
 * patients, practitioners and appointments for Hospital One can never be
 * reached from Hospital Two, and request payloads cannot switch tenant context.
 */
class CoreDomainIsolationTest extends TestCase
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

    /**
     * Hospital One and Hospital Two tenants with their own administrators.
     *
     * @return array{Tenant, Tenant}
     */
    protected function twoHospitals(): array
    {
        $one = $this->provisionTenant('hospital-one', ['email' => 'admin@hospitalone.vee-care.test']);
        $two = $this->provisionTenant('hospital-two', ['email' => 'admin@hospitaltwo.vee-care.test']);

        return [$one, $two];
    }

    protected function registerPatient(string $host, string $email, array $extra = []): TestResponse
    {
        return $this->postJson("http://{$host}/api/auth/register", [
            'name' => 'Test Patient',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            ...$extra,
        ]);
    }

    protected function loginToken(string $host, string $email): string
    {
        $response = $this->postJson("http://{$host}/api/auth/login", [
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertOk();

        return (string) $response->json('token');
    }

    protected function createDoctor(string $host, string $adminToken, string $email): int
    {
        $response = $this->withToken($adminToken)->postJson("http://{$host}/api/admin/users", [
            'name' => 'Dr Test',
            'email' => $email,
            'password' => 'password123',
            'role' => 'doctor',
            'specialty' => 'Cardiology',
        ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /**
     * A patient registered on Hospital One exists only in Hospital One's DB.
     */
    public function test_patient_registered_on_hospital_one_exists_only_in_hospital_one_db(): void
    {
        [$one] = $this->twoHospitals();

        $this->registerPatient('hospital-one.vee-care.test', 'patient@example.com')->assertCreated();

        $this->connectToTenant($one);

        $this->assertSame(1, User::query()->where('email', 'patient@example.com')->count());
        $this->assertSame(
            1,
            PatientProfile::query()->whereHas('user', fn ($q) => $q->where('email', 'patient@example.com'))->count()
        );
    }

    /**
     * The same patient email may exist independently in both tenant databases.
     */
    public function test_duplicate_patient_emails_are_isolated_per_tenant(): void
    {
        [$one, $two] = $this->twoHospitals();

        $this->registerPatient('hospital-one.vee-care.test', 'shared@example.com')->assertCreated();
        $this->registerPatient('hospital-two.vee-care.test', 'shared@example.com')->assertCreated();

        $this->connectToTenant($one);
        $this->assertSame(1, User::query()->where('email', 'shared@example.com')->count());

        $this->connectToTenant($two);
        $this->assertSame(1, User::query()->where('email', 'shared@example.com')->count());
    }

    /**
     * Hospital One cannot retrieve a patient that only exists in Hospital Two.
     */
    public function test_hospital_one_cannot_retrieve_hospital_two_patient(): void
    {
        $this->twoHospitals();

        $this->registerPatient('hospital-one.vee-care.test', 'one@example.com')->assertCreated();
        $this->registerPatient('hospital-one.vee-care.test', 'one.extra@example.com')->assertCreated();
        $this->registerPatient('hospital-two.vee-care.test', 'two@example.com')->assertCreated();

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');
        $twoToken = $this->loginToken('hospital-two.vee-care.test', 'admin@hospitaltwo.vee-care.test');

        // An id that exists only in Hospital One (the extra patient) is invisible from Hospital Two.
        $oneExtraId = $this->patientId('hospital-one.vee-care.test', 'one.extra@example.com');
        $this->withToken($twoToken)
            ->getJson("http://hospital-two.vee-care.test/api/profiles/{$oneExtraId}")
            ->assertNotFound();

        // ...but is visible from Hospital One.
        $this->withToken($oneToken)
            ->getJson("http://hospital-one.vee-care.test/api/profiles/{$oneExtraId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'one.extra@example.com');

        // Colliding auto-increment ids resolve to the tenant-local record: Hospital
        // Two's id 2 is Hospital Two's own patient, never Hospital One's.
        $twoPatientId = $this->patientId('hospital-two.vee-care.test', 'two@example.com');
        $this->withToken($twoToken)
            ->getJson("http://hospital-two.vee-care.test/api/profiles/{$twoPatientId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'two@example.com');

        // And Hospital One's id 2 is Hospital One's patient.
        $onePatientId = $this->patientId('hospital-one.vee-care.test', 'one@example.com');
        $this->withToken($oneToken)
            ->getJson("http://hospital-one.vee-care.test/api/profiles/{$onePatientId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'one@example.com');
    }

    /**
     * Practitioners (doctors) are tenant-local.
     */
    public function test_practitioners_are_tenant_local(): void
    {
        [$one] = $this->twoHospitals();

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');
        $twoToken = $this->loginToken('hospital-two.vee-care.test', 'admin@hospitaltwo.vee-care.test');

        $this->createDoctor('hospital-one.vee-care.test', $oneToken, 'doctor.one@example.com');
        $this->createDoctor('hospital-two.vee-care.test', $twoToken, 'doctor.two@example.com');

        $oneDoctors = $this->withToken($oneToken)
            ->getJson('http://hospital-one.vee-care.test/api/doctors')
            ->assertOk()
            ->json('data');

        $twoDoctors = $this->withToken($twoToken)
            ->getJson('http://hospital-two.vee-care.test/api/doctors')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, collect($oneDoctors)->where('email', 'doctor.one@example.com')->count());
        $this->assertSame(0, collect($oneDoctors)->where('email', 'doctor.two@example.com')->count());
        $this->assertSame(0, collect($twoDoctors)->where('email', 'doctor.one@example.com')->count());
        $this->assertSame(1, collect($twoDoctors)->where('email', 'doctor.two@example.com')->count());

        $this->connectToTenant($one);
        $this->assertSame(1, User::query()->where('role', 'doctor')->count());
    }

    /**
     * An appointment belongs to the tenant database it was created in.
     */
    public function test_appointments_are_tenant_local(): void
    {
        [$one] = $this->twoHospitals();

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');
        $twoToken = $this->loginToken('hospital-two.vee-care.test', 'admin@hospitaltwo.vee-care.test');

        $this->registerPatient('hospital-one.vee-care.test', 'patient.one@example.com')->assertCreated();
        $onePatientToken = $this->loginToken('hospital-one.vee-care.test', 'patient.one@example.com');

        $doctorId = $this->createDoctor('hospital-one.vee-care.test', $oneToken, 'doctor.one@example.com');

        $created = $this->withToken($onePatientToken)
            ->postJson('http://hospital-one.vee-care.test/api/appointments', [
                'doctor_id' => $doctorId,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'reason' => 'Follow-up visit',
            ])
            ->assertCreated();

        $appointmentId = (int) $created->json('data.id');

        // Visible from Hospital One.
        $this->withToken($onePatientToken)
            ->getJson("http://hospital-one.vee-care.test/api/appointments/{$appointmentId}")
            ->assertOk();

        // Not resolvable from Hospital Two.
        $this->withToken($twoToken)
            ->getJson("http://hospital-two.vee-care.test/api/appointments/{$appointmentId}")
            ->assertNotFound();

        $this->connectToTenant($one);
        $this->assertSame(1, Appointment::query()->count());
    }

    /**
     * An appointment may never reference a practitioner from another tenant.
     */
    public function test_cross_tenant_appointment_doctor_reference_is_rejected(): void
    {
        [$one] = $this->twoHospitals();

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');
        $twoToken = $this->loginToken('hospital-two.vee-care.test', 'admin@hospitaltwo.vee-care.test');

        $this->registerPatient('hospital-one.vee-care.test', 'patient.one@example.com')->assertCreated();
        $onePatientToken = $this->loginToken('hospital-one.vee-care.test', 'patient.one@example.com');

        $this->createDoctor('hospital-one.vee-care.test', $oneToken, 'doctor.one@example.com');
        $twoDoctorId = $this->createDoctor('hospital-two.vee-care.test', $twoToken, 'doctor.two@example.com');

        // Hospital One patient attempts to book Hospital Two's doctor.
        $this->withToken($onePatientToken)
            ->postJson('http://hospital-one.vee-care.test/api/appointments', [
                'doctor_id' => $twoDoctorId,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'reason' => 'Should fail',
            ])
            ->assertStatus(422);

        // No appointment was created.
        $this->connectToTenant($one);
        $this->assertSame(0, Appointment::query()->count());
    }

    /**
     * A client-supplied organization_id can never switch the tenant context.
     */
    public function test_client_organization_id_cannot_switch_tenant_context(): void
    {
        [$one] = $this->twoHospitals();

        // Tamper with the registration payload.
        $this->registerPatient('hospital-one.vee-care.test', 'patient.one@example.com', [
            'organization_id' => 999999,
            'branch_id' => 999999,
        ])->assertCreated();

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');
        $onePatientToken = $this->loginToken('hospital-one.vee-care.test', 'patient.one@example.com');

        $this->connectToTenant($one);

        $user = User::query()->where('email', 'patient.one@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotSame(999999, $user->organization_id);

        $profile = PatientProfile::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(1, $profile->organization_id);

        // Tamper with the appointment payload.
        $doctorId = $this->createDoctor('hospital-one.vee-care.test', $oneToken, 'doctor.one@example.com');

        $appointment = $this->withToken($onePatientToken)
            ->postJson('http://hospital-one.vee-care.test/api/appointments', [
                'doctor_id' => $doctorId,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'reason' => 'Tampered payload',
                'organization_id' => 999999,
                'branch_id' => 999999,
            ])
            ->assertCreated();

        $stored = Appointment::query()->find($appointment->json('data.id'));
        $this->assertNotNull($stored);
        $this->assertNotSame(999999, $stored->organization_id);
    }

    /**
     * A hospital admin cannot mint platform roles: they are not valid tenant roles.
     */
    public function test_hospital_admin_cannot_escalate_to_super_admin(): void
    {
        [$one] = $this->twoHospitals();

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');

        $this->withToken($oneToken)
            ->postJson('http://hospital-one.vee-care.test/api/admin/users', [
                'name' => 'Fake Super Admin',
                'email' => 'fake@example.com',
                'password' => 'password123',
                'role' => 'platform_super_admin',
            ])
            ->assertStatus(422);

        // An admin cannot create another hospital admin either.
        $this->withToken($oneToken)
            ->postJson('http://hospital-one.vee-care.test/api/admin/users', [
                'name' => 'Fake Admin',
                'email' => 'fakeadmin@example.com',
                'password' => 'password123',
                'role' => 'hospital_admin',
            ])
            ->assertStatus(422);

        // An admin cannot reassign an existing user to a platform role.
        $doctorId = $this->createDoctor('hospital-one.vee-care.test', $oneToken, 'doctor.one@example.com');

        $this->withToken($oneToken)
            ->patchJson("http://hospital-one.vee-care.test/api/admin/users/{$doctorId}", ['role' => 'platform_super_admin'])
            ->assertUnprocessable();

        $this->connectToTenant($one);
        $this->assertSame(0, User::query()->where('role', 'super_admin')->count());
        $this->assertSame(0, User::query()->where('role', 'platform_super_admin')->count());
    }

    /**
     * Platform and tenant credentials never cross the control/tenant boundary.
     */
    public function test_platform_and_tenant_credentials_are_isolated(): void
    {
        $this->twoHospitals();

        $platformUser = PlatformUser::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform@vee-care.test',
            'password' => Hash::make('password123'),
            'role' => 'platform_super_admin',
        ]);
        $platformToken = $platformUser->createToken('platform-web')->plainTextToken;

        $oneToken = $this->loginToken('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test');

        // Platform token works on the platform host.
        $this->withToken($platformToken)
            ->getJson('http://vee-care.test/api/platform/me')
            ->assertOk();

        // Platform token is rejected on a tenant host.
        $this->withToken($platformToken)
            ->getJson('http://hospital-one.vee-care.test/api/auth/me')
            ->assertUnauthorized();

        // Tenant token is rejected on the platform host.
        $this->withToken($oneToken)
            ->getJson('http://vee-care.test/api/platform/me')
            ->assertUnauthorized();
    }

    /**
     * Guard state does not leak a user across tenant contexts (regression).
     */
    public function test_cached_guard_state_does_not_leak_across_tenants(): void
    {
        $this->twoHospitals();

        $this->registerPatient('hospital-one.vee-care.test', 'leak@example.com')->assertCreated();
        $token = $this->loginToken('hospital-one.vee-care.test', 'leak@example.com');

        // Token authenticates on the issuing tenant.
        $this->withToken($token)
            ->getJson('http://hospital-one.vee-care.test/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'leak@example.com');

        // The same token must not authenticate on Hospital Two.
        $this->withToken($token)
            ->getJson('http://hospital-two.vee-care.test/api/auth/me')
            ->assertUnauthorized();

        // Hospital Two's own admin still authenticates fine afterwards.
        $this->loginToken('hospital-two.vee-care.test', 'admin@hospitaltwo.vee-care.test');
    }

    /**
     * Resolve a patient's user id inside a specific tenant database.
     */
    protected function patientId(string $host, string $email): int
    {
        $tenant = app(\App\Services\TenantResolver::class)->resolve($host);
        $this->assertNotNull($tenant);

        $this->connectToTenant($tenant);

        return (int) User::query()->where('email', $email)->value('id');
    }
}
