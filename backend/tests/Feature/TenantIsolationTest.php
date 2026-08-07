<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
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
     * Test 1: Hospital One resolves to the correct tenant.
     */
    public function test_hospital_one_resolves_correctly(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        $resolved = app(TenantResolver::class)->resolve('hospital-one.vee-care.test');

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->id, $resolved->id);
        $this->assertSame('hospital-one', $resolved->slug);
    }

    /**
     * Test 2: Hospital Two resolves to the correct tenant.
     */
    public function test_hospital_two_resolves_correctly(): void
    {
        $this->provisionTenant('hospital-one');
        $tenant = $this->provisionTenant('hospital-two');

        $resolved = app(TenantResolver::class)->resolve('hospital-two.vee-care.test');

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->id, $resolved->id);
        $this->assertSame('hospital-two', $resolved->slug);
    }

    /**
     * Test 3: Hospital One queries its own database.
     */
    public function test_hospital_one_queries_its_own_database(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        $this->connectToTenant($tenant);

        $this->assertStringContainsString(
            'hospital_one',
            (string) DB::connection()->getDatabaseName(),
        );

        $patient = User::query()->create([
            'name' => 'Patient One',
            'email' => 'patient-one@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->assertTrue(User::query()->where('email', 'patient-one@example.com')->exists());
        $this->assertSame('Patient One', $patient->name);
    }

    /**
     * Test 4: Hospital Two queries its own database.
     */
    public function test_hospital_two_queries_its_own_database(): void
    {
        $this->provisionTenant('hospital-one');
        $tenant = $this->provisionTenant('hospital-two');

        $this->connectToTenant($tenant);

        $this->assertStringContainsString(
            'hospital_two',
            (string) DB::connection()->getDatabaseName(),
        );

        $patient = User::query()->create([
            'name' => 'Patient Two',
            'email' => 'patient-two@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->assertTrue(User::query()->where('email', 'patient-two@example.com')->exists());
        $this->assertSame('Patient Two', $patient->name);
    }

    /**
     * Test 5: Hospital One cannot access Hospital Two's data.
     */
    public function test_hospital_one_cannot_access_hospital_two_data(): void
    {
        $tenantOne = $this->provisionTenant('hospital-one');
        $tenantTwo = $this->provisionTenant('hospital-two');

        $this->connectToTenant($tenantOne);
        User::query()->create([
            'name' => 'Patient One',
            'email' => 'unique-one@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->connectToTenant($tenantTwo);
        User::query()->create([
            'name' => 'Patient Two',
            'email' => 'unique-two@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->assertTrue(User::query()->where('email', 'unique-two@example.com')->exists());
        $this->assertFalse(User::query()->where('email', 'unique-one@example.com')->exists());

        $this->connectToTenant($tenantOne);
        $this->assertTrue(User::query()->where('email', 'unique-one@example.com')->exists());
        $this->assertFalse(User::query()->where('email', 'unique-two@example.com')->exists());
    }

    /**
     * Test 5b: The same email may exist in two different tenants.
     */
    public function test_same_email_can_exist_in_different_tenants(): void
    {
        $tenantOne = $this->provisionTenant('hospital-one');
        $tenantTwo = $this->provisionTenant('hospital-two');

        $this->connectToTenant($tenantOne);
        User::query()->create([
            'name' => 'Shared',
            'email' => 'shared@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->connectToTenant($tenantTwo);
        User::query()->create([
            'name' => 'Shared',
            'email' => 'shared@example.com',
            'password' => 'password123',
            'role' => 'patient',
        ]);

        $this->assertSame(1, User::query()->where('email', 'shared@example.com')->count());
        $this->connectToTenant($tenantOne);
        $this->assertSame(1, User::query()->where('email', 'shared@example.com')->count());
    }

    /**
     * Test 6: Unknown tenant domains are rejected.
     */
    public function test_unknown_tenant_domain_is_rejected(): void
    {
        $this->provisionTenant('hospital-one');

        $response = $this->getJson('http://unknown.vee-care.test/api/auth/me');

        $response->assertStatus(404);
    }

    /**
     * Test 6b: Platform subdomains are not treated as tenants.
     */
    public function test_platform_domain_is_not_resolved_as_tenant(): void
    {
        $this->provisionTenant('hospital-one');

        $resolver = app(TenantResolver::class);

        $this->assertTrue($resolver->isPlatformHost('vee-care.test'));
        $this->assertTrue($resolver->isPlatformHost('api.vee-care.test'));
        $this->assertFalse($resolver->isPlatformHost('hospital-one.vee-care.test'));
        $this->assertNull($resolver->resolve('vee-care.test'));
    }

    /**
     * Test 7: Tenant provisioning creates the correct database and schema.
     */
    public function test_provisioning_creates_database_and_schema(): void
    {
        $tenant = $this->provisionTenant('hospital-one', [
            'email' => 'admin@hospitalone.vee-care.test',
            'password' => 'password123',
        ]);

        $this->assertNotNull($tenant->id);
        $this->assertSame('active', $tenant->status);
        $this->assertSame('vee_care_tenant_hospital_one', $tenant->database_name);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'hospital-one',
            'database_name' => 'vee_care_tenant_hospital_one',
        ]);

        $this->assertDatabaseHas('tenant_domains', [
            'domain' => 'hospital-one.vee-care.test',
            'is_primary' => true,
        ]);

        $this->assertFileExists(
            rtrim((string) config('tenancy.database.sqlite_path'), '/\\').'/vee_care_tenant_hospital_one.sqlite',
        );

        $this->connectToTenant($tenant);

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('appointments'));
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('ehr_entries'));
        $this->assertTrue(Schema::hasTable('medicines'));

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame('Hospital One Tenant', Organization::query()->first()?->name);
        $this->assertSame(1, Branch::query()->count());

        $admin = User::query()->where('role', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin@hospitalone.vee-care.test', $admin->email);
        $this->assertSame(1, $admin->organization_id);
    }

    /**
     * Test 8: Tenant authentication remains isolated between tenants.
     */
    public function test_tenant_authentication_is_isolated(): void
    {
        $this->provisionTenant('hospital-one', ['email' => 'admin@hospitalone.vee-care.test']);
        $this->provisionTenant('hospital-two', ['email' => 'admin@hospitaltwo.vee-care.test']);

        $login = fn (string $host, string $email) => $this->postJson(
            "http://{$host}/api/auth/login",
            ['email' => $email, 'password' => 'password123'],
        );

        $login('hospital-one.vee-care.test', 'admin@hospitalone.vee-care.test')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@hospitalone.vee-care.test');

        $login('hospital-two.vee-care.test', 'admin@hospitaltwo.vee-care.test')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@hospitaltwo.vee-care.test');

        $login('hospital-two.vee-care.test', 'admin@hospitalone.vee-care.test')
            ->assertStatus(422);

        $login('hospital-one.vee-care.test', 'admin@hospitaltwo.vee-care.test')
            ->assertStatus(422);
    }

    /**
     * Test 8b: A token issued by one tenant is rejected by another tenant.
     */
    public function test_tenant_tokens_do_not_cross_tenant_boundaries(): void
    {
        $this->provisionTenant('hospital-one', ['email' => 'admin@hospitalone.vee-care.test']);
        $this->provisionTenant('hospital-two', ['email' => 'admin@hospitaltwo.vee-care.test']);

        $token = $this->postJson(
            'http://hospital-one.vee-care.test/api/auth/login',
            ['email' => 'admin@hospitalone.vee-care.test', 'password' => 'password123'],
        )->json('token');

        $this->assertIsString($token);

        $this->withToken($token)
            ->getJson('http://hospital-one.vee-care.test/api/auth/me')
            ->assertOk();

        $this->withToken($token)
            ->getJson('http://hospital-two.vee-care.test/api/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Test 9: Organizations created by a tenant are auto-linked inside the tenant database.
     */
    public function test_tenant_created_records_are_linked_to_tenant_organization(): void
    {
        $tenant = $this->provisionTenant('hospital-one');

        $this->connectToTenant($tenant);

        $organization = Organization::query()->first();

        $branch = Branch::query()->create([
            'organization_id' => $organization->id,
            'name' => 'North Wing',
        ]);

        $this->assertSame($organization->id, $branch->organization_id);
    }

    /**
     * Test 10: The control database never stores plaintext tenant credentials.
     */
    public function test_tenant_credentials_are_encrypted_at_rest(): void
    {
        $this->provisionTenant('hospital-one', ['database_password' => 'super-secret']);

        $raw = DB::connection('control')
            ->table('tenants')
            ->where('slug', 'hospital-one')
            ->value('database_password');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('super-secret', (string) $raw);

        $decrypted = Tenant::query()->where('slug', 'hospital-one')->first()?->database_password;
        $this->assertSame('super-secret', $decrypted);
    }
}
