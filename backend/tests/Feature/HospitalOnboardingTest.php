<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\HospitalAdminInvitation;
use App\Models\HospitalApplication;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Hospital onboarding / platform administration tests.
 *
 * Verifies the application -> approval -> provisioning -> active lifecycle,
 * the split between platform roles (control DB) and tenant roles (tenant DBs),
 * and that suspended/rejected tenants are blocked at the tenant boundary.
 */
class HospitalOnboardingTest extends TestCase
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

    protected function submitApplication(array $payload): TestResponse
    {
        return $this->postJson('http://vee-care.test/api/platform/hospital-applications', $payload);
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

    protected function defaultPayload(): array
    {
        return [
            'hospital_name' => 'Mercy Hospital',
            'slug' => 'mercy-hospital',
            'type' => 'hospital',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@mercyhospital.test',
            'contact_phone' => '+1000000000',
            'description' => 'A general hospital.',
        ];
    }

    /**
     * A public application submission creates a pending application and never a tenant.
     */
    public function test_public_application_creates_pending_application_without_tenant(): void
    {
        $response = $this->submitApplication($this->defaultPayload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.hospitalName', 'Mercy Hospital')
            ->assertJsonPath('data.slug', 'mercy-hospital')
            ->assertJsonPath('data.contactEmail', 'jane@mercyhospital.test');

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Tenant::query()->where('slug', 'mercy-hospital')->count());
    }

    /**
     * A subdomain already being applied for cannot be requested again.
     */
    public function test_duplicate_slug_is_rejected(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $this->submitApplication($this->defaultPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    /**
     * Platform subdomains (api/admin/www) are reserved.
     */
    public function test_reserved_slug_is_rejected(): void
    {
        $this->submitApplication([...$this->defaultPayload(), 'slug' => 'api'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    /**
     * Malformed subdomains are rejected; case is normalized.
     */
    public function test_invalid_slug_is_rejected(): void
    {
        $this->submitApplication([...$this->defaultPayload(), 'slug' => '-leading'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        $this->submitApplication([...$this->defaultPayload(), 'slug' => 'bad slug'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');

        // Case is normalized to lowercase, not rejected.
        $this->submitApplication([...$this->defaultPayload(), 'slug' => 'Mercy-Hospital'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'mercy-hospital');
    }

    /**
     * Public submissions can never set roles, passwords or database credentials.
     */
    public function test_application_never_accepts_roles_or_database_credentials(): void
    {
        $payload = $this->defaultPayload() + [
            'role' => 'platform_super_admin',
            'password' => 'hunter2',
            'database_name' => 'stolen_db',
        ];

        $this->submitApplication($payload)->assertCreated();

        $application = HospitalApplication::query()->where('slug', 'mercy-hospital')->firstOrFail();
        $this->assertNull($application->tenant_id);
        $this->assertSame('pending', $application->status);
    }

    /**
     * A rejected subdomain becomes available again; a rejected application never provisions a tenant.
     */
    public function test_rejection_creates_no_tenant_and_frees_the_slug(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/reject', [
                'reason' => 'Out of service area.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reviewNotes', 'Out of service area.');

        $this->assertSame(0, Tenant::query()->count());

        // The slug is available again for a fresh application.
        $this->submitApplication($this->defaultPayload())->assertCreated();
    }

    /**
     * Reviewing marks an application as under review.
     */
    public function test_review_marks_application_under_review(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken();

        $this->withToken($token)
            ->patchJson('http://vee-care.test/api/platform/hospital-applications/1')
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review');
    }

    /**
     * Approval provisions an active tenant and issues a single-use invitation
     * instead of returning a password.
     */
    public function test_approval_provisions_active_tenant_with_hospital_admin(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken();

        $response = $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve');

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.tenantId', 1);

        // No plaintext credentials are ever returned.
        $response->assertJsonMissing(['initialPassword']);
        $response->assertJsonMissing(['password']);

        $invitation = $response->json('invitation');
        $this->assertIsString($invitation['token']);
        $this->assertGreaterThanOrEqual(32, strlen($invitation['token']));
        $this->assertSame('jane@mercyhospital.test', $invitation['email']);
        $this->assertStringContainsString('/invitations/'.$invitation['token'].'/accept', $invitation['acceptUrl']);

        // Only the token digest is stored; the raw token is never persisted.
        $stored = HospitalAdminInvitation::query()->where('application_id', 1)->firstOrFail();
        $this->assertSame(hash('sha256', $invitation['token']), $stored->token_hash);
        $this->assertNotSame($invitation['token'], $stored->token_hash);
        $this->assertNull($stored->used_at);
        $this->assertFalse($stored->isUsed());
        $this->assertFalse($stored->isExpired());

        $tenant = Tenant::query()->where('slug', 'mercy-hospital')->firstOrFail();
        $this->assertSame(TenantStatus::Active->value, $tenant->status);

        // The tenant database holds exactly one hospital admin, no platform roles.
        $this->connectToTenant($tenant);

        $admin = User::query()->where('email', 'jane@mercyhospital.test')->firstOrFail();
        $this->assertSame('hospital_admin', $admin->role);

        $this->assertSame(0, User::query()->whereIn('role', ['super_admin', 'admin', 'platform_super_admin', 'platform_admin'])->count());
    }

    /**
     * An approved application cannot be approved or reviewed again.
     */
    public function test_terminal_application_cannot_be_processed_again(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertOk();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertStatus(422);

        $this->withToken($token)
            ->patchJson('http://vee-care.test/api/platform/hospital-applications/1')
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/reject')
            ->assertStatus(422);
    }

    /**
     * A platform_admin (non-super) may manage applications and tenants.
     */
    public function test_platform_admin_can_manage_applications(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken('platform_admin');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/hospital-applications')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending');

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertOk();
    }

    /**
     * A control-plane user without a platform role cannot access platform routes.
     */
    public function test_non_platform_role_cannot_access_platform_routes(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken('patient');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/hospital-applications')
            ->assertForbidden();
    }

    /**
     * A tenant hospital_admin is never treated as a platform administrator:
     * they cannot list, approve or reject applications, manage tenants, or
     * reach any platform route.
     */
    public function test_hospital_admin_cannot_access_platform_routes(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken('hospital_admin');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/hospital-applications')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/reject')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/tenants')
            ->assertForbidden();

        // The application was untouched by the attempted actions.
        $this->assertSame('pending', HospitalApplication::query()->find(1)?->status);
        $this->assertSame(0, Tenant::query()->count());
    }

    /**
     * A tenant token never authenticates against the platform guard.
     */
    public function test_tenant_token_is_rejected_on_platform_host(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $platformToken = $this->platformToken();
        $approval = $this->withToken($platformToken)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertOk();
        $invitationToken = $approval->json('invitation.token');

        $accepted = $this->postJson(
            'http://vee-care.test/api/platform/hospital-applications/invitations/'.$invitationToken.'/accept',
            [
                'name' => 'Jane Doe',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ],
        );
        $accepted->assertOk();

        $tenant = Tenant::query()->where('slug', 'mercy-hospital')->firstOrFail();
        $this->connectToTenant($tenant);

        $login = $this->postJson('http://mercy-hospital.vee-care.test/api/auth/login', [
            'email' => 'jane@mercyhospital.test',
            'password' => 'new-password-123',
        ]);

        $this->disconnectFromTenant();

        $login->assertOk();
        $tenantToken = $login->json('token');

        $this->withToken($tenantToken)
            ->getJson('http://vee-care.test/api/platform/me')
            ->assertUnauthorized();
    }

    /**
     * A suspended tenant blocks all requests at the tenant boundary.
     */
    public function test_suspended_tenant_blocks_requests(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $platformToken = $this->platformToken();
        $this->withToken($platformToken)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertOk();

        $tenant = Tenant::query()->where('slug', 'mercy-hospital')->firstOrFail();
        $tenant->update(['status' => TenantStatus::Suspended->value]);

        $this->assertTrue($tenant->isSuspended());
        $this->assertFalse($tenant->isActive());

        $this->getJson('http://mercy-hospital.vee-care.test/api/posts')->assertStatus(403);
    }

    /**
     * A rejected application's slug cannot collide with an existing tenant at approval time.
     */
    public function test_slug_claimed_by_existing_tenant_is_rejected_at_approval(): void
    {
        $this->provisionTenant('mercy-hospital');

        $this->submitApplication([...$this->defaultPayload(), 'contact_email' => 'other@mercyhospital.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    /**
     * Platform index supports filtering applications by status.
     */
    public function test_platform_application_index_filters_by_status(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $token = $this->platformToken();

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/hospital-applications?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->getJson('http://vee-care.test/api/platform/hospital-applications?status=approved')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A provisioning failure must not leave a half-created tenant or mark the
     * application as approved.
     */
    public function test_approval_failure_leaves_application_pending_without_tenant(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $this->mock(TenantProvisioner::class, function ($mock): void {
            $mock->shouldReceive('provision')->andThrow(new \RuntimeException('Disk full'));
        });

        $token = $this->platformToken();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors('application');

        $this->assertSame('pending', HospitalApplication::query()->find(1)?->status);
        $this->assertNull(HospitalApplication::query()->find(1)?->tenant_id);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, HospitalAdminInvitation::query()->count());
    }

    /**
     * Control-plane audit events are recorded for the whole onboarding lifecycle.
     */
    public function test_onboarding_lifecycle_is_audited(): void
    {
        $this->submitApplication($this->defaultPayload())->assertCreated();

        $this->assertDatabaseHas('platform_audit_logs', [
            'event' => 'hospital_application.submitted',
            'application_id' => 1,
            'platform_user_id' => null,
        ]);

        $token = $this->platformToken();
        $approver = PlatformUser::query()->where('email', 'operator@vee-care.test')->firstOrFail();

        $approval = $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'event' => 'hospital_application.approved',
            'application_id' => 1,
            'tenant_id' => 1,
            'platform_user_id' => $approver->id,
        ]);

        $invitationToken = $approval->json('invitation.token');

        $this->postJson(
            'http://vee-care.test/api/platform/hospital-applications/invitations/'.$invitationToken.'/accept',
            [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ],
        )->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'event' => 'hospital_application.invitation_accepted',
            'application_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->submitApplication([...$this->defaultPayload(), 'slug' => 'second-hospital'])->assertCreated();

        $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/2/reject', [
                'reason' => 'Out of service area.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'event' => 'hospital_application.rejected',
            'application_id' => 2,
            'platform_user_id' => $approver->id,
        ]);
    }

    /**
     * The public onboarding endpoints carry dedicated rate limits.
     */
    public function test_public_onboarding_endpoints_are_rate_limited(): void
    {
        // Hospital application submissions: 5 per minute per client.
        foreach (range(0, 4) as $i) {
            $this->submitApplication([...$this->defaultPayload(), 'slug' => "hospital-{$i}"])
                ->assertCreated();
        }

        $this->submitApplication([...$this->defaultPayload(), 'slug' => 'hospital-over-limit'])
            ->assertStatus(429);

        // Invitation redemption: 10 per minute per client. The first attempt
        // redeems the invitation, the rest are rejected at the controller, and
        // the eleventh is rejected by the rate limiter.
        $token = $this->platformToken();
        $approval = $this->withToken($token)
            ->postJson('http://vee-care.test/api/platform/hospital-applications/1/approve')
            ->assertOk();
        $invitationToken = $approval->json('invitation.token');

        foreach (range(0, 9) as $i) {
            $this->postJson(
                'http://vee-care.test/api/platform/hospital-applications/invitations/'.$invitationToken.'/accept',
                [
                    'password' => 'new-password-123',
                    'password_confirmation' => 'new-password-123',
                ],
            )->assertStatus($i === 0 ? 200 : 422);
        }

        $this->postJson(
            'http://vee-care.test/api/platform/hospital-applications/invitations/'.$invitationToken.'/accept',
            [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ],
        )->assertStatus(429);
    }
}
