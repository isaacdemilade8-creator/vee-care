<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Hospital-admin self-service configuration tests.
 *
 * Covers the tenant-facing /api/configuration surface: hospital admins may
 * read and update their own hospital's branding/modules/roles/settings/name,
 * non-admin tenant roles are forbidden, the active tenant is resolved from the
 * request host (never a client id), and nothing sensitive is ever exposed.
 */
class HospitalConfigurationTest extends TestCase
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

    protected function configUrl(Tenant $tenant): string
    {
        return "http://{$tenant->slug}.vee-care.test/api/configuration";
    }

    protected function login(Tenant $tenant, string $email, string $password): TestResponse
    {
        return $this->postJson("http://{$tenant->slug}.vee-care.test/api/auth/login", [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * @return array{tenant: Tenant, token: string}
     */
    protected function provisionAndLogin(string $slug): array
    {
        $tenant = $this->provisionTenant($slug);

        $response = $this->login($tenant, "admin@{$slug}.vee-care.test", 'password123');
        $response->assertOk();

        return ['tenant' => $tenant, 'token' => $response->json('token')];
    }

    protected function createTenantUser(Tenant $tenant, string $role, string $email): User
    {
        $this->connectToTenant($tenant);

        return User::query()->create([
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
        ]);
    }

    public function test_hospital_admin_can_read_own_configuration(): void
    {
        $result = $this->provisionAndLogin('hospital-one');
        $tenant = $result['tenant'];

        $response = $this->withToken($result['token'])->getJson($this->configUrl($tenant));

        $response->assertOk()
            ->assertJsonPath('name', $tenant->name)
            ->assertJsonPath('settings.timezone', 'UTC')
            ->assertJsonPath('modules.appointments.required', true)
            ->assertJsonPath('modules.pharmacy.required', false)
            ->assertJsonPath('modules.pharmacy.enabled', true)
            ->assertJsonPath('roles.hospital_admin.required', true)
            ->assertJsonPath('roles.pharmacist.required', false)
            ->assertJsonPath('branding.font_family', null);

        $this->assertCount(count(config('tenant-defaults.modules')), $response->json('modules'));
        $this->assertCount(count(config('tenant-defaults.roles')), $response->json('roles'));
    }

    public function test_hospital_admin_can_update_configuration(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $response = $this->withToken($result['token'])->patchJson($this->configUrl($result['tenant']), [
            'name' => 'St. Mary\'s Hospital',
            'branding' => ['primary_color' => '#1d4ed8', 'font_family' => 'Inter'],
            'modules' => ['pharmacy' => false],
            'roles' => ['pharmacist' => false],
            'settings' => ['timezone' => 'Europe/London'],
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'St. Mary\'s Hospital')
            ->assertJsonPath('branding.primary_color', '#1d4ed8')
            ->assertJsonPath('branding.font_family', 'Inter')
            ->assertJsonPath('modules.pharmacy.enabled', false)
            ->assertJsonPath('roles.pharmacist.enabled', false)
            ->assertJsonPath('settings.timezone', 'Europe/London');

        $this->assertSame('St. Mary\'s Hospital', $result['tenant']->fresh()->name);
    }

    public function test_configuration_change_is_audited(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])->patchJson($this->configUrl($result['tenant']), [
            'branding' => ['primary_color' => '#1d4ed8'],
            'settings' => ['timezone' => 'Europe/London'],
        ])->assertOk();

        $log = PlatformAuditLog::query()
            ->where('event', 'tenant.configuration.updated')
            ->where('tenant_id', $result['tenant']->id)
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->platform_user_id);
        $this->assertSame(['branding', 'settings'], $log->metadata['categories']);
        $this->assertContains('primary_color', $log->metadata['keys']['branding']);
        $this->assertSame('Hospital One Tenant Administrator', $log->metadata['actor']['name']);
        $this->assertSame('hospital_admin', $log->metadata['actor']['role']);
    }

    public function test_unchanged_configuration_is_not_audited(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($result['tenant']), ['settings' => ['timezone' => 'UTC']])
            ->assertOk();

        $this->assertDatabaseMissing('platform_audit_logs', [
            'event' => 'tenant.configuration.updated',
            'tenant_id' => $result['tenant']->id,
        ], 'control');
    }

    public function test_unknown_settings_key_is_rejected(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($result['tenant']), ['settings' => ['theme' => 'dark']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings');
    }

    public function test_required_module_cannot_be_disabled(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($result['tenant']), ['modules' => ['appointments' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.appointments');
    }

    public function test_platform_roles_are_rejected_as_tenant_roles(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($result['tenant']), ['roles' => ['platform_super_admin' => true]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_non_admin_tenant_roles_are_forbidden(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        foreach (['doctor', 'nurse', 'patient', 'pharmacist', 'lab_technician'] as $role) {
            $user = $this->createTenantUser($tenant, $role, "{$role}@hospital-one.vee-care.test");
            $login = $this->login($tenant, $user->email, 'password123');
            $login->assertOk();

            $this->withToken($login->json('token'))
                ->getJson($this->configUrl($tenant))
                ->assertStatus(403);

            $this->withToken($login->json('token'))
                ->patchJson($this->configUrl($tenant), ['settings' => ['timezone' => 'UTC']])
                ->assertStatus(403);
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        $this->getJson($this->configUrl($tenant))->assertStatus(401);
        $this->patchJson($this->configUrl($tenant), ['name' => 'Hacked'])->assertStatus(401);
    }

    public function test_tenant_tokens_cannot_cross_tenant_boundaries(): void
    {
        $first = $this->provisionAndLogin('hospital-one');
        $second = $this->provisionTenant('hospital-two');

        // A token minted by hospital-one's database is meaningless on the
        // hospital-two host: the tenant DB binding differs, so the request is
        // rejected as unauthenticated rather than leaking configuration.
        $this->withToken($first['token'])
            ->getJson($this->configUrl($second))
            ->assertStatus(401);
    }

    public function test_configuration_response_never_exposes_database_details(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $response = $this->withToken($result['token'])->getJson($this->configUrl($result['tenant']));

        $response->assertOk();
        $response->assertJsonMissingPath('database_name');
        $response->assertJsonMissingPath('database_password');
        $response->assertJsonMissingPath('database_host');
        $response->assertJsonMissingPath('database_username');

        $this->assertStringNotContainsString('vee_care_tenant_', $response->getContent());
    }

    public function test_hospital_admin_can_reset_branding_field_to_inherit(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($result['tenant']), ['branding' => ['primary_color' => '#1d4ed8']])
            ->assertJsonPath('branding.primary_color', '#1d4ed8');

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($result['tenant']), ['branding' => ['primary_color' => '']])
            ->assertJsonPath('branding.primary_color', null);
    }
}
