<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Backend module-gating tests.
 *
 * Covers the EnsureTenantModuleEnabled middleware that backs the frontend
 * module toggles: disabling an optional module (pharmacy, laboratory, ...)
 * must block its routes with 403 for the affected tenant only, enabled
 * modules stay reachable, and required modules can never be blocked even if a
 * stale configuration row were ever written.
 */
class TenantModuleGateTest extends TestCase
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

    protected function login(Tenant $tenant): TestResponse
    {
        return $this->postJson("http://{$tenant->slug}.vee-care.test/api/auth/login", [
            'email' => "admin@{$tenant->slug}.vee-care.test",
            'password' => 'password123',
        ]);
    }

    /**
     * @return array{tenant: Tenant, token: string}
     */
    protected function provisionAndLogin(string $slug): array
    {
        $tenant = $this->provisionTenant($slug);

        $response = $this->login($tenant);
        $response->assertOk();

        return ['tenant' => $tenant, 'token' => $response->json('token')];
    }

    protected function configUrl(Tenant $tenant): string
    {
        return "http://{$tenant->slug}.vee-care.test/api/configuration";
    }

    public function test_enabled_optional_module_allows_its_routes(): void
    {
        $result = $this->provisionAndLogin('hospital-one');

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/pharmacy/medicines')
            ->assertOk();
    }

    public function test_disabled_module_blocks_its_routes(): void
    {
        $result = $this->provisionAndLogin('hospital-one');
        $tenant = $result['tenant'];

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($tenant), ['modules' => ['pharmacy' => false]])
            ->assertOk()
            ->assertJsonPath('modules.pharmacy.enabled', false);

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/pharmacy/medicines')
            ->assertForbidden();

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/pharmacy/requests')
            ->assertForbidden();
    }

    public function test_disabled_module_blocks_enterprise_sub_routes(): void
    {
        $result = $this->provisionAndLogin('hospital-one');
        $tenant = $result['tenant'];

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($tenant), ['modules' => ['pharmacy' => false]])
            ->assertOk();

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/enterprise/pharmacy')
            ->assertForbidden();

        $this->withToken($result['token'])
            ->postJson('http://hospital-one.vee-care.test/api/enterprise/medicines', [
                'name' => 'Paracetamol',
            ])
            ->assertForbidden();

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/enterprise/lab-tests')
            ->assertOk();
    }

    public function test_disabled_laboratory_blocks_lab_routes_only(): void
    {
        $result = $this->provisionAndLogin('hospital-one');
        $tenant = $result['tenant'];

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($tenant), ['modules' => ['laboratory' => false]])
            ->assertOk();

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/enterprise/lab-tests')
            ->assertForbidden();

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/pharmacy/medicines')
            ->assertOk();
    }

    public function test_disabled_module_blocks_public_routes(): void
    {
        $result = $this->provisionAndLogin('hospital-one');
        $tenant = $result['tenant'];

        $this->withToken($result['token'])
            ->patchJson($this->configUrl($tenant), ['modules' => ['blog' => false]])
            ->assertOk();

        $this->getJson('http://hospital-one.vee-care.test/api/posts')
            ->assertForbidden();
    }

    public function test_required_module_always_passes_even_with_stale_disabled_row(): void
    {
        $result = $this->provisionAndLogin('hospital-one');
        $tenant = $result['tenant'];

        // The configuration API refuses to disable required modules, but a
        // stale row written around it must never block a mandatory capability.
        $this->connectToTenant($tenant);
        $tenant->modules()->where('module', 'appointments')->update(['enabled' => false]);
        $this->disconnectFromTenant();

        $this->withToken($result['token'])
            ->getJson('http://hospital-one.vee-care.test/api/appointments')
            ->assertOk();
    }

    public function test_module_gating_is_per_tenant(): void
    {
        $disabled = $this->provisionAndLogin('hospital-one');
        $enabled = $this->provisionAndLogin('hospital-two');

        $this->withToken($disabled['token'])
            ->patchJson($this->configUrl($disabled['tenant']), ['modules' => ['pharmacy' => false]])
            ->assertOk();

        $this->withToken($disabled['token'])
            ->getJson('http://hospital-one.vee-care.test/api/pharmacy/medicines')
            ->assertForbidden();

        $this->withToken($enabled['token'])
            ->getJson('http://hospital-two.vee-care.test/api/pharmacy/medicines')
            ->assertOk();
    }
}
