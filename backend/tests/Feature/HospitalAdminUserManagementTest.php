<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Hospital user-management acceptance tests for the hospital-admin surfaces.
 *
 * Every endpoint runs inside the tenant database resolved from the request
 * host, so users, invitations and lifecycle actions are scoped to one hospital:
 * another tenant's user id yields a 404 and another tenant's invitation token
 * can never be redeemed. The single-use invitation flow is exercised end to
 * end (invite -> pending list -> accept -> login, revoke, re-accept blocked).
 */
class HospitalAdminUserManagementTest extends TestCase
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
     * @return array{tenant: Tenant, token: string}
     */
    protected function provisionAndLoginAdmin(string $slug): array
    {
        $tenant = $this->provisionTenant($slug);

        $response = $this->login($tenant, "admin@{$slug}.vee-care.test");
        $response->assertOk();

        return ['tenant' => $tenant, 'token' => $response->json('token')];
    }

    protected function createUser(string $tenantHost, string $adminToken, array $payload): TestResponse
    {
        return $this->withToken($adminToken)->postJson($tenantHost.'/api/admin/users', $payload);
    }

    public function test_admin_manages_a_user_end_to_end_in_their_own_hospital(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $host = $this->tenantHost($hospitalOne['tenant']);
        $token = $hospitalOne['token'];

        $create = $this->createUser($host, $token, [
            'name' => 'Nurse One',
            'email' => 'nurse@hospital-one.vee-care.test',
            'password' => 'password123',
            'role' => 'nurse',
            'specialty' => 'Nursing',
        ])->assertCreated();

        $userId = (int) $create->json('data.id');
        $this->assertSame('nurse', $create->json('data.role'));
        $this->assertSame('active', $create->json('data.status'));

        $this->withToken($token)->getJson($host.'/api/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['email' => 'nurse@hospital-one.vee-care.test']);

        $this->withToken($token)->getJson($host."/api/admin/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.email', 'nurse@hospital-one.vee-care.test')
            ->assertJsonPath('data.specialty', 'Nursing');

        $this->withToken($token)->patchJson($host."/api/admin/users/{$userId}", [
            'name' => 'Nurse One Renamed',
            'phone' => '+1000000001',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Nurse One Renamed')
            ->assertJsonPath('data.phone', '+1000000001');

        // Deactivation blocks login; reactivation restores it.
        $this->withToken($token)->postJson($host."/api/admin/users/{$userId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->login($hospitalOne['tenant'], 'nurse@hospital-one.vee-care.test')->assertStatus(422);

        $this->withToken($token)->postJson($host."/api/admin/users/{$userId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->login($hospitalOne['tenant'], 'nurse@hospital-one.vee-care.test')->assertOk();

        $this->withToken($token)->deleteJson($host."/api/admin/users/{$userId}")->assertOk();

        $this->withToken($token)->getJson($host."/api/admin/users/{$userId}")->assertNotFound();
    }

    public function test_user_management_is_scoped_to_each_hospital(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $hospitalTwo = $this->provisionAndLoginAdmin('hospital-two');

        $created = $this->createUser($this->tenantHost($hospitalOne['tenant']), $hospitalOne['token'], [
            'name' => 'Doctor One',
            'email' => 'doctor@hospital-one.vee-care.test',
            'password' => 'password123',
            'role' => 'doctor',
        ])->assertCreated();

        $userId = (int) $created->json('data.id');

        // Hospital Two's directory never includes Hospital One's staff.
        $this->withToken($hospitalTwo['token'])->getJson($this->tenantHost($hospitalTwo['tenant']).'/api/admin/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'admin@hospital-two.vee-care.test');

        // Cross-tenant detail/lifecycle/delete all 404.
        $this->withToken($hospitalTwo['token'])->getJson($this->tenantHost($hospitalTwo['tenant'])."/api/admin/users/{$userId}")
            ->assertNotFound();
        $this->withToken($hospitalTwo['token'])->patchJson($this->tenantHost($hospitalTwo['tenant'])."/api/admin/users/{$userId}", ['name' => 'Hijacked'])
            ->assertNotFound();
        $this->withToken($hospitalTwo['token'])->postJson($this->tenantHost($hospitalTwo['tenant'])."/api/admin/users/{$userId}/deactivate")
            ->assertNotFound();
        $this->withToken($hospitalTwo['token'])->deleteJson($this->tenantHost($hospitalTwo['tenant'])."/api/admin/users/{$userId}")
            ->assertNotFound();
    }

    public function test_invitation_flow_is_scoped_to_the_issuing_hospital(): void
    {
        Mail::fake();

        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $hospitalTwo = $this->provisionAndLoginAdmin('hospital-two');
        $hostOne = $this->tenantHost($hospitalOne['tenant']);

        $invite = $this->withToken($hospitalOne['token'])->postJson($hostOne.'/api/admin/users/invitations', [
            'email' => 'pharmacist@hospital-one.vee-care.test',
            'role' => 'pharmacist',
        ])->assertCreated();

        $token = (string) $invite->json('invitation.token');
        $this->assertSame(64, strlen($token));
        $this->assertStringContainsString($token, (string) $invite->json('invitation.acceptUrl'));

        // The issuing hospital lists it as pending; the other hospital does not.
        $this->withToken($hospitalOne['token'])->getJson($hostOne.'/api/admin/users')
            ->assertOk()
            ->assertJsonCount(1, 'pending')
            ->assertJsonPath('pending.0.email', 'pharmacist@hospital-one.vee-care.test')
            ->assertJsonPath('pending.0.role', 'pharmacist');

        $this->withToken($hospitalTwo['token'])->getJson($this->tenantHost($hospitalTwo['tenant']).'/api/admin/users')
            ->assertOk()
            ->assertJsonCount(0, 'pending');

        // The token only redeems on the issuing host.
        $this->postJson($this->tenantHost($hospitalTwo['tenant']).'/api/admin/users/invitations/'.$token.'/accept', [
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('token');

        $this->postJson($hostOne.'/api/admin/users/invitations/'.$token.'/accept', [
            'name' => 'Pharmacist One',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.email', 'pharmacist@hospital-one.vee-care.test')
            ->assertJsonPath('user.role', 'pharmacist')
            ->assertJsonMissing(['token', 'password']);

        // The accepted user can sign in at Hospital One and is no longer pending.
        $this->login($hospitalOne['tenant'], 'pharmacist@hospital-one.vee-care.test')->assertOk();

        $this->withToken($hospitalOne['token'])->getJson($hostOne.'/api/admin/users')
            ->assertOk()
            ->assertJsonCount(0, 'pending');

        // A used token cannot be redeemed twice.
        $this->postJson($hostOne.'/api/admin/users/invitations/'.$token.'/accept', [
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_invitation_can_be_revoked_and_stays_scoped(): void
    {
        Mail::fake();

        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $hospitalTwo = $this->provisionAndLoginAdmin('hospital-two');
        $hostOne = $this->tenantHost($hospitalOne['tenant']);

        $invite = $this->withToken($hospitalOne['token'])->postJson($hostOne.'/api/admin/users/invitations', [
            'email' => 'lab@hospital-one.vee-care.test',
            'role' => 'lab_technician',
        ])->assertCreated();

        $token = (string) $invite->json('invitation.token');

        // Hospital Two cannot revoke Hospital One's invitation (404).
        $this->withToken($hospitalTwo['token'])
            ->deleteJson($this->tenantHost($hospitalTwo['tenant']).'/api/admin/users/invitations/1')
            ->assertNotFound();

        $pending = $this->withToken($hospitalOne['token'])->getJson($hostOne.'/api/admin/users')->assertOk();
        $invitationId = collect($pending->json('pending'))->firstWhere('email', 'lab@hospital-one.vee-care.test')['id'];

        $this->withToken($hospitalOne['token'])
            ->deleteJson($hostOne."/api/admin/users/invitations/{$invitationId}")
            ->assertOk();

        // A revoked invitation cannot be redeemed...
        $this->postJson($hostOne.'/api/admin/users/invitations/'.$token.'/accept', [
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('token');

        // ...nor revoked twice.
        $this->withToken($hospitalOne['token'])
            ->deleteJson($hostOne."/api/admin/users/invitations/{$invitationId}")
            ->assertStatus(422);
    }
}
