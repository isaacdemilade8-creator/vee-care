<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * End-to-end acceptance tests across two fully independent hospitals and the
 * platform.
 *
 * Verifies the acceptance criteria from the production-readiness audit: each
 * hospital's branding, module state and role state are isolated; disabling a
 * module or role is reversible and never destroys data; the platform can
 * suspend and reactivate a hospital without losing its data; the production
 * API never honors a client-declared host; and hostile branding input is
 * rejected at the API surface.
 */
class TwoHospitalAcceptanceTest extends TestCase
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

    protected function sqlitePath(Tenant $tenant): string
    {
        $directory = config('tenancy.database.sqlite_path');

        return rtrim($directory, '/\\').'/'.$tenant->database_name.'.sqlite';
    }

    protected function createMedicine(Tenant $tenant, string $adminToken, string $name): TestResponse
    {
        return $this->withToken($adminToken)
            ->postJson($this->tenantHost($tenant).'/api/enterprise/medicines', [
                'name' => $name,
                'stock' => 50,
                'unit_price' => 100,
            ]);
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

    public function test_two_hospitals_keep_independent_branding_and_identity(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $hospitalTwo = $this->provisionAndLoginAdmin('hospital-two');

        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($hospitalOne['tenant']), [
                'name' => 'Mercy General',
                'branding' => [
                    'primary_color' => '#1e40af',
                    'font_family' => 'Inter',
                    'logo' => 'https://cdn.mercy.example/logo.svg',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Mercy General')
            ->assertJsonPath('branding.primary_color', '#1e40af')
            ->assertJsonPath('branding.font_family', 'Inter')
            ->assertJsonPath('branding.logo', 'https://cdn.mercy.example/logo.svg');

        // Hospital Two must be completely unaffected.
        $this->withToken($hospitalTwo['token'])
            ->getJson($this->configUrl($hospitalTwo['tenant']))
            ->assertOk()
            ->assertJsonPath('name', 'Hospital Two Tenant')
            ->assertJsonPath('branding.primary_color', null)
            ->assertJsonPath('branding.font_family', null)
            ->assertJsonPath('branding.logo', null);

        // The public bootstrap endpoint exposes each hospital's own branding.
        $this->getJson($this->tenantHost($hospitalOne['tenant']).'/api/tenant-context')
            ->assertOk()
            ->assertJsonPath('context', 'tenant')
            ->assertJsonPath('tenant.name', 'Mercy General')
            ->assertJsonPath('tenant.branding.primaryColor', '#1e40af')
            ->assertJsonPath('tenant.branding.fontFamily', 'Inter');

        $this->getJson($this->tenantHost($hospitalTwo['tenant']).'/api/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant.name', 'Hospital Two Tenant')
            ->assertJsonPath('tenant.branding.primaryColor', null)
            ->assertJsonPath('tenant.branding.fontFamily', null);
    }

    public function test_two_hospitals_keep_independent_role_state(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $hospitalTwo = $this->provisionAndLoginAdmin('hospital-two');

        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($hospitalOne['tenant']), ['roles' => ['pharmacist' => false]])
            ->assertOk()
            ->assertJsonPath('roles.pharmacist.enabled', false);

        // Hospital Two still has the role enabled and its pharmacist can work.
        $this->withToken($hospitalTwo['token'])
            ->getJson($this->configUrl($hospitalTwo['tenant']))
            ->assertOk()
            ->assertJsonPath('roles.pharmacist.enabled', true);

        $this->withToken($hospitalTwo['token'])
            ->postJson($this->tenantHost($hospitalTwo['tenant']).'/api/admin/users', [
                'name' => 'Pharmacist Two',
                'email' => 'pharmacist@hospital-two.vee-care.test',
                'password' => 'password123',
                'role' => 'pharmacist',
            ])
            ->assertCreated();

        $login = $this->login($hospitalTwo['tenant'], 'pharmacist@hospital-two.vee-care.test');
        $login->assertOk();

        $this->withToken($login->json('token'))
            ->getJson($this->tenantHost($hospitalTwo['tenant']).'/api/pharmacy/medicines')
            ->assertOk();
    }

    public function test_disabling_a_module_is_reversible_and_preserves_data(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $hospitalOne['tenant'];

        $this->createMedicine($tenant, $hospitalOne['token'], 'Paracetamol 500mg')->assertCreated();

        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($tenant), ['modules' => ['pharmacy' => false]])
            ->assertOk()
            ->assertJsonPath('modules.pharmacy.enabled', false);

        // Routes are blocked for the affected tenant only.
        $this->withToken($hospitalOne['token'])
            ->getJson($this->tenantHost($tenant).'/api/pharmacy/medicines')
            ->assertForbidden();

        // Disabling the module never destroys the inventory data.
        $this->connectToTenant($tenant);
        $this->assertSame(1, Medicine::query()->count());
        $this->disconnectFromTenant();

        // Re-enabling restores functionality immediately.
        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($tenant), ['modules' => ['pharmacy' => true]])
            ->assertOk()
            ->assertJsonPath('modules.pharmacy.enabled', true);

        $this->withToken($hospitalOne['token'])
            ->getJson($this->tenantHost($tenant).'/api/pharmacy/medicines')
            ->assertOk();

        // Both changes were audited on the control plane.
        $audits = PlatformAuditLog::query()
            ->where('event', 'tenant.configuration.updated')
            ->where('tenant_id', $tenant->id)
            ->get();

        $this->assertCount(2, $audits);
        $this->assertSame(['modules'], $audits->first()->metadata['categories']);
        $this->assertSame(['pharmacy'], $audits->first()->metadata['keys']['modules']);
    }

    public function test_disabling_a_role_is_reversible_and_preserves_the_account(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $hospitalOne['tenant'];

        $this->withToken($hospitalOne['token'])
            ->postJson($this->tenantHost($tenant).'/api/admin/users', [
                'name' => 'Pharmacist One',
                'email' => 'pharmacist@hospital-one.vee-care.test',
                'password' => 'password123',
                'role' => 'pharmacist',
            ])
            ->assertCreated();

        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($tenant), ['roles' => ['pharmacist' => false]])
            ->assertOk();

        $this->login($tenant, 'pharmacist@hospital-one.vee-care.test')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Disabling a role never deletes the account or its historical data.
        $this->connectToTenant($tenant);
        $this->assertSame(1, User::query()->where('role', 'pharmacist')->count());
        $this->disconnectFromTenant();

        // Re-enabling the role restores login and route access.
        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($tenant), ['roles' => ['pharmacist' => true]])
            ->assertOk()
            ->assertJsonPath('roles.pharmacist.enabled', true);

        $login = $this->login($tenant, 'pharmacist@hospital-one.vee-care.test');
        $login->assertOk();

        $this->withToken($login->json('token'))
            ->getJson($this->tenantHost($tenant).'/api/pharmacy/medicines')
            ->assertOk();
    }

    public function test_platform_can_suspend_and_reactivate_without_losing_data(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $hospitalOne['tenant'];

        $this->createMedicine($tenant, $hospitalOne['token'], 'Ibuprofen 400mg')->assertCreated();

        $platformToken = $this->platformToken();

        // Suspend the tenant through the platform surface.
        $this->withToken($platformToken)
            ->patchJson("http://vee-care.test/api/platform/tenants/{$tenant->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        // Every tenant-host request is now rejected while suspended.
        $this->getJson($this->tenantHost($tenant).'/api/tenant-context')->assertForbidden();
        $this->postJson($this->tenantHost($tenant).'/api/auth/login', [
            'email' => "admin@{$tenant->slug}.vee-care.test",
            'password' => 'password123',
        ])->assertForbidden();

        // Suspension must not destroy the tenant database.
        $this->assertFileExists($this->sqlitePath($tenant));

        // Reactivate: the same hospital works again and its data is intact.
        $this->withToken($platformToken)
            ->patchJson("http://vee-care.test/api/platform/tenants/{$tenant->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->getJson($this->tenantHost($tenant).'/api/tenant-context')
            ->assertOk()
            ->assertJsonPath('context', 'tenant');

        $login = $this->login($tenant, "admin@{$tenant->slug}.vee-care.test");
        $login->assertOk();

        $this->withToken($login->json('token'))
            ->getJson($this->tenantHost($tenant).'/api/pharmacy/medicines')
            ->assertOk();

        $this->connectToTenant($tenant);
        $this->assertSame(1, Medicine::query()->count());
        $this->disconnectFromTenant();

        // Both lifecycle changes were audited on the control plane.
        $this->assertSame(2, PlatformAuditLog::query()
            ->where('event', 'tenant.updated')
            ->where('tenant_id', $tenant->id)
            ->count());
    }

    public function test_production_never_honors_a_client_declared_host(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');

        $original = app()->environment();
        app()->instance('env', 'production');

        try {
            // The client-declared ?host= must be ignored in production: the
            // platform host still resolves as platform context.
            $this->getJson('http://vee-care.test/api/tenant-context?host=hospital-one.vee-care.test')
                ->assertOk()
                ->assertJsonPath('context', 'platform')
                ->assertJsonPath('tenant', null);

            // The real request Host remains the source of truth.
            $this->getJson($this->tenantHost($hospitalOne['tenant']).'/api/tenant-context')
                ->assertOk()
                ->assertJsonPath('context', 'tenant')
                ->assertJsonPath('tenant.slug', 'hospital-one');
        } finally {
            app()->instance('env', $original);
        }
    }

    public function test_invalid_branding_input_is_rejected(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $tenant = $hospitalOne['tenant'];

        foreach ([
            ['logo' => 'javascript:alert(1)'],
            ['favicon' => 'data:text/html;base64,PGh0bWw+PC9odG1sPg=='],
            ['favicon' => 'http://evil.example.com/favicon.ico'],
            ['primary_color' => 'red'],
            ['primary_color' => '#12345'],
            ['font_family' => 'Comic Sans'],
        ] as $badBranding) {
            $this->withToken($hospitalOne['token'])
                ->patchJson($this->configUrl($tenant), ['branding' => $badBranding])
                ->assertStatus(422);
        }

        // A safe https asset still passes.
        $this->withToken($hospitalOne['token'])
            ->patchJson($this->configUrl($tenant), ['branding' => [
                'logo' => 'https://cdn.mercy.example/logo.svg',
                'primary_color' => '#0f766e',
                'font_family' => 'Inter',
            ]])
            ->assertOk()
            ->assertJsonPath('branding.logo', 'https://cdn.mercy.example/logo.svg')
            ->assertJsonPath('branding.primary_color', '#0f766e');
    }
}
