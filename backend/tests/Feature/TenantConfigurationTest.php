<?php

namespace Tests\Feature;

use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Tenant configuration tests.
 *
 * Covers the platform configuration surface (GET/PATCH
 * /api/platform/tenants/{tenant}/configuration): reads, updates, validation,
 * required-module/role enforcement, platform-role rejection, provisioning
 * defaults, auditing and the safe public tenant-context projection.
 */
class TenantConfigurationTest extends TestCase
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
        $user = PlatformUser::query()->firstOrCreate(
            ['email' => 'operator@vee-care.test'],
            ['name' => 'Platform Operator', 'password' => Hash::make('password123')],
        );

        $user->update(['role' => $role]);

        return $user->createToken('platform-web')->plainTextToken;
    }

    protected function createTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => Str::title(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'type' => 'hospital',
            'plan' => 'starter',
            'status' => 'active',
            'currency' => 'USD',
            'database_name' => 'vee_care_tenant_'.$slug,
        ]);
    }

    protected function configUrl(Tenant $tenant): string
    {
        return "http://vee-care.test/api/platform/tenants/{$tenant->id}/configuration";
    }

    protected function show(Tenant $tenant): TestResponse
    {
        return $this->withToken($this->platformToken())->getJson($this->configUrl($tenant));
    }

    protected function patchConfig(Tenant $tenant, array $payload, string $role = 'platform_super_admin'): TestResponse
    {
        return $this->withToken($this->platformToken($role))->patchJson($this->configUrl($tenant), $payload);
    }

    public function test_platform_admin_can_read_configuration_with_defaults(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $response = $this->show($tenant);

        $response->assertOk()
            ->assertJsonPath('data.settings.timezone', 'UTC')
            ->assertJsonPath('data.modules.appointments.required', true)
            ->assertJsonPath('data.modules.pharmacy.required', false)
            ->assertJsonPath('data.modules.pharmacy.enabled', true)
            ->assertJsonPath('data.roles.hospital_admin.required', true)
            ->assertJsonPath('data.roles.pharmacist.required', false)
            ->assertJsonPath('data.branding.font_family', null);

        $this->assertCount(count(config('tenant-defaults.modules')), $response->json('data.modules'));
        $this->assertCount(count(config('tenant-defaults.roles')), $response->json('data.roles'));
    }

    public function test_authorized_platform_admin_can_update_configuration(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $response = $this->patchConfig($tenant, [
            'branding' => ['primary_color' => '#1d4ed8', 'font_family' => 'Inter'],
            'settings' => ['timezone' => 'Africa/Lagos'],
            'modules' => ['pharmacy' => false],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.branding.primary_color', '#1d4ed8')
            ->assertJsonPath('data.branding.font_family', 'Inter')
            ->assertJsonPath('data.settings.timezone', 'Africa/Lagos')
            ->assertJsonPath('data.modules.pharmacy.enabled', false);
    }

    public function test_unknown_module_is_rejected(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['modules' => ['billing' => true]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.billing');
    }

    public function test_required_module_cannot_be_disabled(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['modules' => ['appointments' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.appointments');
    }

    public function test_invalid_color_is_rejected(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['branding' => ['primary_color' => 'red']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branding.primary_color');

        $this->patchConfig($tenant, ['branding' => ['primary_color' => 'var(--app-accent)']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branding.primary_color');
    }

    public function test_invalid_font_is_rejected(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['branding' => ['font_family' => 'Comic Sans MS; background: url(evil)']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branding.font_family');
    }

    public function test_invalid_asset_url_is_rejected(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['branding' => ['logo' => 'javascript:alert(1)']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branding.logo');

        $this->patchConfig($tenant, ['branding' => ['favicon' => 'data:image/png;base64,AAAA']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branding.favicon');

        $this->patchConfig($tenant, ['branding' => ['logo' => 'http://evil.example/logo.png']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branding.logo');
    }

    public function test_loopback_http_asset_url_is_accepted_in_dev(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, [
            'branding' => [
                'logo' => 'http://127.0.0.1:8000/storage/uploads/branding/logo.png',
                'favicon' => 'http://localhost:8000/storage/uploads/branding/favicon.png',
            ],
        ])->assertOk()
            ->assertJsonPath('data.branding.logo', 'http://127.0.0.1:8000/storage/uploads/branding/logo.png')
            ->assertJsonPath('data.branding.favicon', 'http://localhost:8000/storage/uploads/branding/favicon.png');
    }

    public function test_unknown_setting_is_rejected(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['settings' => ['theme' => 'dark']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings');
    }

    public function test_platform_roles_are_rejected_as_tenant_roles(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['roles' => ['platform_super_admin' => true]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->patchConfig($tenant, ['roles' => ['platform_admin' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_required_role_cannot_be_disabled(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['roles' => ['hospital_admin' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles.hospital_admin');

        $this->patchConfig($tenant, ['roles' => ['patient' => false]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles.patient');
    }

    public function test_optional_role_can_be_disabled(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['roles' => ['pharmacist' => false]])
            ->assertOk()
            ->assertJsonPath('data.roles.pharmacist.enabled', false);
    }

    public function test_non_platform_role_cannot_access_configuration(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->withToken($this->platformToken('patient'))
            ->getJson($this->configUrl($tenant))
            ->assertForbidden();

        $this->withToken($this->platformToken('hospital_admin'))
            ->patchJson($this->configUrl($tenant), ['settings' => ['timezone' => 'UTC']])
            ->assertForbidden();
    }

    public function test_tenant_user_cannot_access_platform_configuration_endpoints(): void
    {
        $tenant = $this->provisionTenant('hospital-one');
        $this->connectToTenant($tenant);

        $login = $this->postJson('http://hospital-one.vee-care.test/api/auth/login', [
            'email' => 'admin@hospital-one.vee-care.test',
            'password' => 'password123',
        ]);

        $login->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('http://vee-care.test/api/platform/tenants/'.$tenant->id.'/configuration')
            ->assertStatus(401);

        $this->withToken($login->json('token'))
            ->patchJson('http://vee-care.test/api/platform/tenants/'.$tenant->id.'/configuration', [
                'settings' => ['timezone' => 'UTC'],
            ])
            ->assertStatus(401);

        $this->disconnectFromTenant();
    }

    public function test_newly_provisioned_tenant_gets_defaults(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        $this->assertNotNull($tenant->branding);

        $response = $this->show($tenant);

        $response->assertOk();

        foreach (config('tenant-defaults.modules') as $key => $meta) {
            $response->assertJsonPath("data.modules.{$key}.enabled", true);
        }

        foreach (config('tenant-defaults.roles') as $role => $meta) {
            $response->assertJsonPath("data.roles.{$role}.enabled", true);
        }

        $this->assertCount(count(config('tenant-defaults.modules')), $response->json('data.modules'));
    }

    public function test_configuration_change_is_audited(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, [
            'branding' => ['primary_color' => '#1d4ed8'],
            'modules' => ['pharmacy' => false],
            'settings' => ['timezone' => 'Europe/London'],
        ])->assertOk();

        $log = PlatformAuditLog::query()
            ->where('event', 'tenant.configuration.updated')
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['branding', 'modules', 'settings'], $log->metadata['categories']);
        $this->assertContains('primary_color', $log->metadata['keys']['branding']);
        $this->assertContains('pharmacy', $log->metadata['keys']['modules']);
        $this->assertContains('timezone', $log->metadata['keys']['settings']);
    }

    public function test_unchanged_configuration_does_not_audit(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['settings' => ['timezone' => 'UTC']])->assertOk();

        $this->assertDatabaseMissing('platform_audit_logs', [
            'event' => 'tenant.configuration.updated',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_tenant_context_exposes_public_configuration_only(): void
    {
        $tenant = $this->provisionTenant('hospital-one');
        $this->connectToTenant($tenant);

        $this->patchConfig($tenant, [
            'modules' => ['pharmacy' => false],
        ])->assertOk();

        $this->disconnectFromTenant();

        $response = $this->getJson('http://hospital-one.vee-care.test/api/tenant-context');

        $response->assertOk()
            ->assertJsonPath('tenant.modules.appointments.enabled', true)
            ->assertJsonPath('tenant.modules.appointments.required', true)
            ->assertJsonPath('tenant.modules.pharmacy.enabled', false)
            ->assertJsonPath('tenant.modules.pharmacy.required', false)
            ->assertJsonPath('tenant.branding.primaryColor', null);

        $response->assertJsonMissingPath('tenant.settings');
        $response->assertJsonMissingPath('tenant.roles');
        $response->assertJsonMissingPath('tenant.database_name');
        $response->assertJsonMissingPath('tenant.database_password');
    }

    public function test_tenant_context_never_hides_a_required_module(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        // The API refuses to disable a required module, but even a stale row
        // written around it must not hide the capability from the public UI.
        $tenant->modules()->where('module', 'appointments')->update(['enabled' => false]);

        $this->getJson('http://hospital-one.vee-care.test/api/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant.modules.appointments.enabled', true)
            ->assertJsonPath('tenant.modules.appointments.required', true)
            ->assertJsonPath('tenant.modules.pharmacy.enabled', true)
            ->assertJsonPath('tenant.modules.pharmacy.required', false);
    }

    public function test_lifecycle_settings_must_respect_the_allowlist(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->patchConfig($tenant, ['settings' => ['theme' => 'dark']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings');
    }

    public function test_lifecycle_settings_accept_allowlisted_values(): void
    {
        $tenant = $this->createTenant('hospital-one');

        $this->withToken($this->platformToken())
            ->patchJson("http://vee-care.test/api/platform/tenants/{$tenant->id}", [
                'settings' => ['timezone' => 'Europe/London'],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.timezone', 'Europe/London');
    }

    public function test_tenant_lifecycle_actions_are_audited(): void
    {
        $tenant = $this->createTenant('hospital-one');
        $tenant->domains()->create(['domain' => 'hospital-one.vee-care.test', 'is_primary' => true]);
        $token = $this->platformToken();

        $this->withToken($token)
            ->patchJson("http://vee-care.test/api/platform/tenants/{$tenant->id}", ['name' => 'Hospital One Renamed'])
            ->assertOk();

        $added = $this->withToken($token)
            ->postJson("http://vee-care.test/api/platform/tenants/{$tenant->id}/domains", [
                'domain' => 'one.example.com',
            ])
            ->assertOk();

        $domainId = collect($added->json('data.domains'))->firstWhere('is_primary', false)['id'];

        $this->withToken($token)
            ->deleteJson("http://vee-care.test/api/platform/tenants/{$tenant->id}/domains/{$domainId}")
            ->assertOk();

        foreach (['tenant.updated', 'tenant.domain_added', 'tenant.domain_removed'] as $event) {
            $this->assertDatabaseHas('platform_audit_logs', [
                'event' => $event,
                'tenant_id' => $tenant->id,
            ], 'control');
        }
    }

    public function test_platform_login_is_audited(): void
    {
        PlatformUser::query()->create([
            'name' => 'Platform Operator',
            'email' => 'operator@vee-care.test',
            'password' => Hash::make('password123'),
            'role' => 'platform_super_admin',
        ]);

        $this->postJson('http://vee-care.test/api/platform/auth/login', [
            'email' => 'operator@vee-care.test',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'event' => 'platform.auth.login',
        ], 'control');
    }

    public function test_login_endpoint_is_throttled(): void
    {
        $this->provisionTenant('hospital-one');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('http://hospital-one.vee-care.test/api/auth/login', [
                'email' => 'admin@hospital-one.vee-care.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('http://hospital-one.vee-care.test/api/auth/login', [
            'email' => 'admin@hospital-one.vee-care.test',
            'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_declared_inactive_tenant_host_falls_back_to_platform_context(): void
    {
        $tenant = $this->provisionTenant('hospital-one');
        $tenant->update(['status' => 'suspended']);

        $this->getJson('http://localhost/api/tenant-context?host=hospital-one.vee-care.test')
            ->assertOk()
            ->assertJsonPath('context', 'platform')
            ->assertJsonPath('tenant', null);
    }
}
