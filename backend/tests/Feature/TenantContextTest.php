<?php

namespace Tests\Feature;

use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

class TenantContextTest extends TestCase
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

    public function test_tenant_context_returns_public_tenant_with_branding(): void
    {
        $tenant = $this->provisionTenant('hospital-one');
        $tenant->update([
            'settings' => [
                'branding' => [
                    'logo' => '/storage/logos/hospital-one.png',
                    'favicon' => '/storage/favicons/hospital-one.ico',
                    'primary_color' => '#1d4ed8',
                    'secondary_color' => '#0f766e',
                    'accent_color' => '#f59e0b',
                    'font_family' => 'Inter',
                ],
            ],
        ]);

        $response = $this->getJson('http://hospital-one.vee-care.test/api/tenant-context');

        $response
            ->assertOk()
            ->assertJsonPath('context', 'tenant')
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('tenant.name', 'Hospital One Tenant')
            ->assertJsonPath('tenant.slug', 'hospital-one')
            ->assertJsonPath('tenant.status', 'active')
            ->assertJsonPath('tenant.branding.logo', '/storage/logos/hospital-one.png')
            ->assertJsonPath('tenant.branding.primaryColor', '#1d4ed8')
            ->assertJsonPath('tenant.branding.fontFamily', 'Inter');

        // No internal/database/credential fields may leak.
        $response->assertJsonMissingPath('tenant.database_name');
        $response->assertJsonMissingPath('tenant.database_host');
        $response->assertJsonMissingPath('tenant.database_username');
        $response->assertJsonMissingPath('tenant.database_password');
        $response->assertJsonMissingPath('tenant.settings');
    }

    public function test_tenant_context_without_branding_returns_nulls(): void
    {
        $this->provisionTenant('hospital-one');

        $this->getJson('http://hospital-one.vee-care.test/api/tenant-context')
            ->assertOk()
            ->assertJsonPath('context', 'tenant')
            ->assertJsonPath('tenant.branding.logo', null)
            ->assertJsonPath('tenant.branding.primaryColor', null);
    }

    public function test_platform_host_returns_platform_context(): void
    {
        $this->provisionTenant('hospital-one');

        $this->getJson('http://localhost/api/tenant-context')
            ->assertOk()
            ->assertJsonPath('context', 'platform')
            ->assertJsonPath('tenant', null);
    }

    public function test_declared_tenant_host_is_honored_in_dev(): void
    {
        $tenant = $this->provisionTenant('hospital-one');
        $tenant->update([
            'settings' => ['branding' => ['primary_color' => '#1d4ed8']],
        ]);

        // The browser calls the API at a loopback URL while the page is served
        // from the tenant subdomain; the declared host resolves the tenant.
        $this->getJson('http://localhost/api/tenant-context?host=hospital-one.vee-care.test')
            ->assertOk()
            ->assertJsonPath('context', 'tenant')
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('tenant.branding.primaryColor', '#1d4ed8');
    }

    public function test_declared_unknown_tenant_host_falls_back_to_platform(): void
    {
        $this->provisionTenant('hospital-one');

        $this->getJson('http://localhost/api/tenant-context?host=unknown.vee-care.test')
            ->assertOk()
            ->assertJsonPath('context', 'platform')
            ->assertJsonPath('tenant', null);
    }

    public function test_unknown_host_is_rejected(): void
    {
        $this->provisionTenant('hospital-one');

        $this->getJson('http://unknown.vee-care.test/api/tenant-context')->assertStatus(404);
    }
}
