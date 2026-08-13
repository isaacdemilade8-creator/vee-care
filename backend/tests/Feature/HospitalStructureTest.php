<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenantDatabase;
use Tests\TestCase;

/**
 * Hospital structure acceptance tests (departments, wards, rooms, beds).
 *
 * The structure endpoints run inside the tenant database resolved from the
 * request host and behind `role:hospital_admin`, so every entity is scoped to
 * one hospital (another tenant's id 404s) and only hospital administrators can
 * reach them. Capacity is kept consistent across the hierarchy (ward capacity
 * >= sum of room capacities, room capacity >= its beds) and deleting an entity
 * that still has children is refused in favour of deactivation.
 */
class HospitalStructureTest extends TestCase
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
     * @return array{tenant: Tenant, host: string, token: string}
     */
    protected function provisionAndLoginAdmin(string $slug): array
    {
        $tenant = $this->provisionTenant($slug);
        $host = $this->tenantHost($tenant);

        $response = $this->login($tenant, "admin@{$slug}.vee-care.test");
        $response->assertOk();

        return ['tenant' => $tenant, 'host' => $host, 'token' => $response->json('token')];
    }

    /**
     * @return array{tenant: Tenant, host: string, token: string}
     */
    protected function provisionAndLoginDoctor(string $slug): array
    {
        $admin = $this->provisionAndLoginAdmin($slug);

        $this->withToken($admin['token'])->postJson($admin['host'].'/api/admin/users', [
            'name' => 'Doctor One',
            'email' => "doctor@{$slug}.vee-care.test",
            'password' => 'password123',
            'role' => 'doctor',
        ])->assertCreated();

        $response = $this->login($admin['tenant'], "doctor@{$slug}.vee-care.test");
        $response->assertOk();

        return ['tenant' => $admin['tenant'], 'host' => $admin['host'], 'token' => $response->json('token')];
    }

    /**
     * Build a department -> ward -> room -> bed chain and return the created
     * ids keyed by entity.
     *
     * @return array{department: int, ward: int, room: int, bed: int}
     */
    protected function createChain(string $host, string $token): array
    {
        $department = $this->withToken($token)->postJson($host.'/api/admin/departments', [
            'name' => 'Cardiology',
            'description' => 'Heart and vascular care.',
        ])->assertCreated();

        $ward = $this->withToken($token)->postJson($host.'/api/admin/wards', [
            'department_id' => $department->json('data.id'),
            'name' => 'Cardiology Ward',
            'type' => 'icu',
            'capacity' => 10,
        ])->assertCreated();

        $room = $this->withToken($token)->postJson($host.'/api/admin/rooms', [
            'ward_id' => $ward->json('data.id'),
            'name' => '101',
            'capacity' => 2,
        ])->assertCreated();

        $bed = $this->withToken($token)->postJson($host.'/api/admin/beds', [
            'room_id' => $room->json('data.id'),
            'bed_number' => '101A',
            'status' => 'available',
        ])->assertCreated();

        return [
            'department' => (int) $department->json('data.id'),
            'ward' => (int) $ward->json('data.id'),
            'room' => (int) $room->json('data.id'),
            'bed' => (int) $bed->json('data.id'),
        ];
    }

    public function test_admin_manages_the_hospital_structure_end_to_end(): void
    {
        $hospital = $this->provisionAndLoginAdmin('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $chain = $this->createChain($host, $token);

        // The ward reports its room and bed counts (beds flow through rooms).
        $this->withToken($token)->getJson($host."/api/admin/wards/{$chain['ward']}")
            ->assertOk()
            ->assertJsonPath('data.roomsCount', 1)
            ->assertJsonPath('data.bedsCount', 1)
            ->assertJsonPath('data.department.name', 'Cardiology');

        $this->withToken($token)->getJson($host."/api/admin/rooms/{$chain['room']}")
            ->assertOk()
            ->assertJsonPath('data.bedsCount', 1)
            ->assertJsonPath('data.ward.name', 'Cardiology Ward');

        $this->withToken($token)->getJson($host."/api/admin/beds/{$chain['bed']}")
            ->assertOk()
            ->assertJsonPath('data.bedNumber', '101A')
            ->assertJsonPath('data.room.ward.name', 'Cardiology Ward');

        // Partial PATCH updates: toggle a ward's lifecycle without resubmitting fields.
        $this->withToken($token)->patchJson($host."/api/admin/wards/{$chain['ward']}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.name', 'Cardiology Ward');

        $this->withToken($token)->patchJson($host."/api/admin/wards/{$chain['ward']}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Renaming a department leaves its ward attached.
        $this->withToken($token)->patchJson($host."/api/admin/departments/{$chain['department']}", ['name' => 'Cardiology & Vascular'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cardiology & Vascular');
    }

    public function test_capacity_rules_are_enforced_across_the_hierarchy(): void
    {
        $hospital = $this->provisionAndLoginAdmin('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $chain = $this->createChain($host, $token);

        // A room whose capacity would exceed the ward's is refused.
        $this->withToken($token)->postJson($host.'/api/admin/rooms', [
            'ward_id' => $chain['ward'],
            'name' => '202',
            'capacity' => 11,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');

        // Ward capacity cannot drop below the sum of its rooms' capacities.
        $this->withToken($token)->patchJson($host."/api/admin/wards/{$chain['ward']}", ['capacity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');

        // Grow the room and add a second bed so the next guard has a target.
        $this->withToken($token)->patchJson($host."/api/admin/rooms/{$chain['room']}", ['capacity' => 3])
            ->assertOk();
        $this->withToken($token)->postJson($host.'/api/admin/beds', [
            'room_id' => $chain['room'],
            'bed_number' => '101B',
        ])->assertCreated();

        // Room capacity cannot drop below its current number of beds.
        $this->withToken($token)->patchJson($host."/api/admin/rooms/{$chain['room']}", ['capacity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');

        // Shrink the room back to capacity 2; a third bed now overflows it.
        $this->withToken($token)->patchJson($host."/api/admin/rooms/{$chain['room']}", ['capacity' => 2])
            ->assertOk();
        $this->withToken($token)->postJson($host.'/api/admin/beds', [
            'room_id' => $chain['room'],
            'bed_number' => '101C',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');

        // A duplicate bed number within the room is refused.
        $this->withToken($token)->postJson($host.'/api/admin/beds', [
            'room_id' => $chain['room'],
            'bed_number' => '101B',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('bed_number');
    }

    public function test_bed_lifecycle_and_operational_status_are_independent(): void
    {
        $hospital = $this->provisionAndLoginAdmin('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $chain = $this->createChain($host, $token);

        // Occupied beds cannot be taken out of service.
        $this->withToken($token)->patchJson($host."/api/admin/beds/{$chain['bed']}", ['status' => 'occupied'])
            ->assertOk();

        $this->withToken($token)->patchJson($host."/api/admin/beds/{$chain['bed']}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        // Freeing the bed first allows deactivation.
        $this->withToken($token)->patchJson($host."/api/admin/beds/{$chain['bed']}", ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.status', 'available');

        $this->withToken($token)->patchJson($host."/api/admin/beds/{$chain['bed']}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.status', 'available');
    }

    public function test_entities_with_dependents_cannot_be_deleted(): void
    {
        $hospital = $this->provisionAndLoginAdmin('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $chain = $this->createChain($host, $token);

        // Full chains refuse deletion at every level.
        $this->withToken($token)->deleteJson($host."/api/admin/departments/{$chain['department']}")
            ->assertStatus(422);
        $this->withToken($token)->deleteJson($host."/api/admin/wards/{$chain['ward']}")
            ->assertStatus(422);
        $this->withToken($token)->deleteJson($host."/api/admin/rooms/{$chain['room']}")
            ->assertStatus(422);

        // Leaf entities can always be deleted.
        $this->withToken($token)->deleteJson($host."/api/admin/beds/{$chain['bed']}")
            ->assertOk();
        $this->withToken($token)->deleteJson($host."/api/admin/rooms/{$chain['room']}")
            ->assertOk();

        // An empty ward and department can now be deleted.
        $this->withToken($token)->deleteJson($host."/api/admin/wards/{$chain['ward']}")
            ->assertOk();
        $this->withToken($token)->deleteJson($host."/api/admin/departments/{$chain['department']}")
            ->assertOk();

        $this->withToken($token)->getJson($host."/api/admin/departments/{$chain['department']}")
            ->assertNotFound();
    }

    public function test_structure_is_scoped_to_each_hospital(): void
    {
        $hospitalOne = $this->provisionAndLoginAdmin('hospital-one');
        $hospitalTwo = $this->provisionAndLoginAdmin('hospital-two');

        $chain = $this->createChain($hospitalOne['host'], $hospitalOne['token']);

        // Hospital Two's list never includes Hospital One's structure.
        $this->withToken($hospitalTwo['token'])->getJson($hospitalTwo['host'].'/api/admin/departments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Cross-tenant reads, updates and deletes all 404.
        $ids = [
            'departments' => $chain['department'],
            'wards' => $chain['ward'],
            'rooms' => $chain['room'],
            'beds' => $chain['bed'],
        ];

        foreach ($ids as $resource => $id) {
            $base = "{$hospitalTwo['host']}/api/admin/{$resource}";

            $this->withToken($hospitalTwo['token'])->getJson("{$base}/{$id}")->assertNotFound();
            $this->withToken($hospitalTwo['token'])->patchJson("{$base}/{$id}", ['status' => 'inactive'])->assertNotFound();
            $this->withToken($hospitalTwo['token'])->deleteJson("{$base}/{$id}")->assertNotFound();
        }

        // The same department name is valid in a different hospital.
        $this->withToken($hospitalTwo['token'])->postJson($hospitalTwo['host'].'/api/admin/departments', [
            'name' => 'Cardiology',
        ])->assertCreated();

        // A second department in Hospital One gets an id that does not exist in
        // Hospital Two's database, so referencing it fails validation locally.
        $neurology = $this->withToken($hospitalOne['token'])->postJson($hospitalOne['host'].'/api/admin/departments', [
            'name' => 'Neurology',
        ])->assertCreated();

        $this->withToken($hospitalTwo['token'])->postJson($hospitalTwo['host'].'/api/admin/wards', [
            'department_id' => $neurology->json('data.id'),
            'name' => 'Foreign Ward',
            'capacity' => 5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_non_admin_users_are_forbidden_from_structure_endpoints(): void
    {
        $doctor = $this->provisionAndLoginDoctor('hospital-one');
        $host = $doctor['host'];

        $this->withToken($doctor['token'])->getJson($host.'/api/admin/departments')
            ->assertForbidden();
        $this->withToken($doctor['token'])->postJson($host.'/api/admin/departments', ['name' => 'Radiology'])
            ->assertForbidden();
        $this->withToken($doctor['token'])->getJson($host.'/api/admin/wards')
            ->assertForbidden();
        $this->withToken($doctor['token'])->getJson($host.'/api/admin/rooms')
            ->assertForbidden();
        $this->withToken($doctor['token'])->getJson($host.'/api/admin/beds')
            ->assertForbidden();
    }

    public function test_list_supports_search_and_status_filters(): void
    {
        $hospital = $this->provisionAndLoginAdmin('hospital-one');
        $host = $hospital['host'];
        $token = $hospital['token'];

        $this->withToken($token)->postJson($host.'/api/admin/departments', ['name' => 'Cardiology'])
            ->assertCreated();
        $this->withToken($token)->postJson($host.'/api/admin/departments', ['name' => 'Radiology'])
            ->assertCreated();

        $this->withToken($token)->getJson($host.'/api/admin/departments?search=cardio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cardiology');

        $this->withToken($token)->patchJson($host.'/api/admin/departments/2', ['status' => 'inactive'])
            ->assertOk();

        $this->withToken($token)->getJson($host.'/api/admin/departments?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Radiology');
    }
}
