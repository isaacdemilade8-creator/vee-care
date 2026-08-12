<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Tenant role-configuration enforcement tests.
 *
 * Disabling an optional tenant role (pharmacist, lab_technician) must be a
 * real control, not a cosmetic toggle: disabled-role users cannot log in,
 * cannot pass role-gated routes, and cannot be created or assigned. A hospital
 * administrator must never be promoted to or from another account's role
 * through the admin surface, and the last hospital administrator can never
 * demote their own account.
 */
class TenantRoleGateTest extends TestCase
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

    protected function configUrl(Tenant $tenant): string
    {
        return $this->tenantHost($tenant).'/api/configuration';
    }

    protected function setRole(Tenant $tenant, string $token, string $role, bool $enabled): void
    {
        $this->withToken($token)
            ->patchJson($this->configUrl($tenant), ['roles' => [$role => $enabled]])
            ->assertOk()
            ->assertJsonPath("roles.{$role}.enabled", $enabled);
    }

    protected function createUser(Tenant $tenant, string $adminToken, string $role, string $email): int
    {
        $response = $this->withToken($adminToken)
            ->postJson($this->tenantHost($tenant).'/api/admin/users', [
                'name' => ucfirst(str_replace('_', ' ', $role)),
                'email' => $email,
                'password' => 'password123',
                'role' => $role,
            ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_disabled_role_cannot_log_in(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->createUser($tenant, $result['token'], 'pharmacist', 'pharmacist@hospital-one.vee-care.test');

        $this->setRole($tenant, $result['token'], 'pharmacist', false);

        $this->login($tenant, 'pharmacist@hospital-one.vee-care.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // The administrator (required role) still signs in fine.
        $this->login($tenant, 'admin@hospital-one.vee-care.test')->assertOk();
    }

    public function test_disabled_role_user_is_blocked_on_role_gated_routes(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->createUser($tenant, $result['token'], 'pharmacist', 'pharmacist@hospital-one.vee-care.test');

        $pharmacistLogin = $this->login($tenant, 'pharmacist@hospital-one.vee-care.test');
        $pharmacistLogin->assertOk();
        $pharmacistToken = $pharmacistLogin->json('token');

        $this->withToken($pharmacistToken)
            ->getJson($this->tenantHost($tenant).'/api/pharmacy/medicines')
            ->assertOk();

        $this->setRole($tenant, $result['token'], 'pharmacist', false);

        // The token minted before the role was disabled is now blocked.
        $this->withToken($pharmacistToken)
            ->getJson($this->tenantHost($tenant).'/api/pharmacy/medicines')
            ->assertForbidden();
    }

    public function test_admin_cannot_create_user_with_a_disabled_role(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->setRole($tenant, $result['token'], 'lab_technician', false);

        $this->withToken($result['token'])
            ->postJson($this->tenantHost($tenant).'/api/admin/users', [
                'name' => 'Lab Technician',
                'email' => 'lab@hospital-one.vee-care.test',
                'password' => 'password123',
                'role' => 'lab_technician',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_admin_cannot_register_staff_with_a_disabled_role(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->setRole($tenant, $result['token'], 'pharmacist', false);

        // The staff-invitation path (POST /api/enterprise/staff) must enforce
        // the same rule as the admin user-creation path.
        $this->withToken($result['token'])
            ->postJson($this->tenantHost($tenant).'/api/enterprise/staff', [
                'name' => 'Pharmacist',
                'email' => 'pharmacist@hospital-one.vee-care.test',
                'password' => 'password123',
                'role' => 'pharmacist',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->connectToTenant($tenant);
        $this->assertSame(0, User::query()->where('email', 'pharmacist@hospital-one.vee-care.test')->count());
        $this->disconnectFromTenant();
    }

    public function test_admin_cannot_register_staff_with_a_platform_role(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->withToken($result['token'])
            ->postJson($this->tenantHost($tenant).'/api/enterprise/staff', [
                'name' => 'Fake Super Admin',
                'email' => 'fake@hospital-one.vee-care.test',
                'password' => 'password123',
                'role' => 'platform_super_admin',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_admin_can_register_staff_with_an_enabled_role(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->withToken($result['token'])
            ->postJson($this->tenantHost($tenant).'/api/enterprise/staff', [
                'name' => 'Doctor',
                'email' => 'doctor@hospital-one.vee-care.test',
                'password' => 'password123',
                'role' => 'doctor',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'doctor');
    }

    public function test_admin_cannot_reassign_a_user_to_a_disabled_role(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $doctorId = $this->createUser($tenant, $result['token'], 'doctor', 'doctor@hospital-one.vee-care.test');

        $this->setRole($tenant, $result['token'], 'pharmacist', false);

        $this->withToken($result['token'])
            ->patchJson($this->tenantHost($tenant)."/api/admin/users/{$doctorId}", ['role' => 'pharmacist'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_admin_cannot_promote_a_user_to_hospital_admin(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $doctorId = $this->createUser($tenant, $result['token'], 'doctor', 'doctor@hospital-one.vee-care.test');

        $this->withToken($result['token'])
            ->patchJson($this->tenantHost($tenant)."/api/admin/users/{$doctorId}", ['role' => 'hospital_admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_last_hospital_admin_cannot_demote_their_own_account(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->connectToTenant($tenant);
        $adminId = User::query()->where('role', 'hospital_admin')->firstOrFail()->id;
        $this->disconnectFromTenant();

        $this->withToken($result['token'])
            ->patchJson($this->tenantHost($tenant)."/api/admin/users/{$adminId}", ['role' => 'doctor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_admin_can_demote_own_account_when_another_admin_exists(): void
    {
        $result = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $result['tenant'];

        $this->connectToTenant($tenant);
        $firstAdminId = User::query()->where('role', 'hospital_admin')->firstOrFail()->id;

        User::query()->create([
            'name' => 'Second Admin',
            'email' => 'second-admin@hospital-one.vee-care.test',
            'password' => Hash::make('password123'),
            'role' => 'hospital_admin',
        ]);

        $this->assertSame(2, User::query()->where('role', 'hospital_admin')->count());
        $this->disconnectFromTenant();

        $this->withToken($result['token'])
            ->patchJson($this->tenantHost($tenant)."/api/admin/users/{$firstAdminId}", ['role' => 'doctor'])
            ->assertOk()
            ->assertJsonPath('data.role', 'doctor');
    }
}
