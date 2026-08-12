<?php

namespace Tests\Feature;

use App\Models\HospitalApplication;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Platform admin dashboard tests.
 *
 * Covers the two platform-read surfaces added for the administration UI:
 * the dashboard summary endpoint and the read-only audit-log index. Both are
 * restricted to platform roles and never leak database credentials.
 */
class PlatformAdminDashboardTest extends TestCase
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

    protected function platformToken(string $role = 'platform_super_admin'): string
    {
        $user = PlatformUser::query()->create([
            'name' => 'Platform Operator',
            'email' => 'operator@vee-care.test',
            'password' => Hash::make('password123'),
            'role' => $role,
        ]);

        return $user->createToken('platform-web')->plainTextToken;
    }

    protected function createTenant(string $slug, string $status): Tenant
    {
        return Tenant::query()->create([
            'name' => Str::title(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'type' => 'hospital',
            'plan' => 'starter',
            'status' => $status,
            'currency' => 'USD',
            'database_name' => 'vee_care_tenant_'.$slug,
        ]);
    }

    protected function createHospitalApplication(string $slug, string $status): HospitalApplication
    {
        return HospitalApplication::query()->create([
            'hospital_name' => Str::title(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'type' => 'hospital',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@'.$slug.'.test',
            'contact_phone' => '+1000000000',
            'description' => 'A general hospital.',
            'status' => $status,
        ]);
    }

    protected function summary(): TestResponse
    {
        return $this->withToken($this->platformToken())
            ->getJson('http://vee-care.test/api/platform/summary');
    }

    /**
     * The dashboard summary returns real, server-computed counts and the five
     * most recent hospitals and applications.
     */
    public function test_summary_returns_real_counts_and_recent_records(): void
    {
        $this->createTenant('hospital-one', 'active');
        $this->createTenant('hospital-two', 'suspended');
        $this->createTenant('hospital-three', 'active');
        $this->createHospitalApplication('mercy-hospital', 'pending');
        $this->createHospitalApplication('hope-hospital', 'approved');

        $response = $this->summary();

        $response->assertOk()
            ->assertJsonPath('hospitals.total', 3)
            ->assertJsonPath('hospitals.active', 2)
            ->assertJsonPath('hospitals.suspended', 1)
            ->assertJsonPath('hospitals.provisioning', 0)
            ->assertJsonPath('hospitals.failed', 0)
            ->assertJsonPath('applications.total', 2)
            ->assertJsonPath('applications.pending', 1)
            ->assertJsonPath('applications.underReview', 0)
            ->assertJsonPath('applications.approved', 1)
            ->assertJsonPath('applications.rejected', 0);

        $this->assertCount(2, $response->json('recentApplications'));
        $this->assertCount(3, $response->json('recentTenants'));
    }

    /**
     * Recent hospital records never expose database credentials or names.
     */
    public function test_summary_recent_tenants_do_not_expose_database_details(): void
    {
        $this->createTenant('hospital-one', 'active');

        $response = $this->summary();

        $response->assertOk()
            ->assertJsonPath('recentTenants.0.name', 'Hospital One')
            ->assertJsonPath('recentTenants.0.status', 'active');

        $response->assertJsonMissingPath('recentTenants.0.database_password');
    }

    /**
     * The summary endpoint is restricted to platform roles.
     */
    public function test_summary_requires_platform_role(): void
    {
        $token = $this->platformToken('patient');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/summary')
            ->assertForbidden();
    }

    /**
     * A tenant hospital_admin never reaches the platform summary.
     */
    public function test_hospital_admin_cannot_access_summary(): void
    {
        $token = $this->platformToken('hospital_admin');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/summary')
            ->assertForbidden();
    }

    /**
     * The audit-log index lists events with their actor and related records.
     */
    public function test_audit_logs_list_events_with_actor_and_relations(): void
    {
        $actor = PlatformUser::query()->create([
            'name' => 'Reviewer',
            'email' => 'reviewer@vee-care.test',
            'password' => Hash::make('password123'),
            'role' => 'platform_admin',
        ]);
        $application = $this->createHospitalApplication('mercy-hospital', 'under_review');
        $tenant = $this->createTenant('mercy-hospital', 'active');

        PlatformAuditLog::query()->create([
            'event' => 'hospital_application.approved',
            'platform_user_id' => $actor->id,
            'application_id' => $application->id,
            'tenant_id' => $tenant->id,
            'metadata' => ['email' => 'jane@mercy-hospital.test'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $token = $this->platformToken('platform_admin');

        $response = $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/audit-logs');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'hospital_application.approved')
            ->assertJsonPath('data.0.actor.name', 'Reviewer')
            ->assertJsonPath('data.0.actor.email', 'reviewer@vee-care.test')
            ->assertJsonPath('data.0.application.hospitalName', 'Mercy Hospital')
            ->assertJsonPath('data.0.tenant.name', 'Mercy Hospital')
            ->assertJsonPath('data.0.metadata.email', 'jane@mercy-hospital.test')
            ->assertJsonPath('data.0.ipAddress', '127.0.0.1');

        $response->assertJsonMissingPath('data.0.tenant.database_password');
        $response->assertJsonMissingPath('data.0.tenant.database_name');
    }

    /**
     * The audit-log index supports filtering by event.
     */
    public function test_audit_logs_filter_by_event(): void
    {
        PlatformAuditLog::query()->create([
            'event' => 'hospital_application.submitted',
            'metadata' => ['slug' => 'mercy-hospital'],
        ]);
        PlatformAuditLog::query()->create([
            'event' => 'hospital_application.rejected',
            'metadata' => ['reason' => 'Out of service area.'],
        ]);

        $token = $this->platformToken();

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/audit-logs?event=hospital_application.rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'hospital_application.rejected');
    }

    /**
     * The audit-log index is restricted to platform roles.
     */
    public function test_audit_logs_require_platform_role(): void
    {
        $token = $this->platformToken('patient');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/audit-logs')
            ->assertForbidden();
    }

    /**
     * Public (unauthenticated) events appear with a null actor.
     */
    public function test_public_events_have_null_actor(): void
    {
        PlatformAuditLog::query()->create([
            'event' => 'hospital_application.submitted',
            'metadata' => ['slug' => 'mercy-hospital'],
        ]);

        $this->withToken($this->platformToken())
            ->getJson('http://vee-care.test/api/platform/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.actor', null);
    }
}
