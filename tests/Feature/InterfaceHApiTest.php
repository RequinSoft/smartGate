<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Group;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterfaceHApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('interfaceh_api_tokens')->insert([
            'id' => 1,
            'token_hash' => hash('sha256', 'test-interfaceh-token'),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_connection_endpoint_confirms_a_valid_source(): void
    {
        $this->withToken('test-interfaceh-token')
            ->postJson('/api/connection', ['source' => 'attendance'])
            ->assertOk()
            ->assertExactJson(['success' => true]);
    }

    public function test_resource_batches_are_saved_and_retries_update_existing_records(): void
    {
        $this->withToken('test-interfaceh-token')
            ->postJson('/api/personnel', [
                'source' => 'attendance',
                'data' => [
                    ['id' => 10, 'personCode' => '0010', 'name' => 'María', 'last_name' => 'Pérez', 'complete_name' => 'María Pérez', 'group_id' => 3],
                    ['id' => 11, 'personCode' => '0011', 'name' => 'Juan', 'last_name' => 'López', 'complete_name' => 'Juan López', 'group_id' => 3],
                ],
            ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'accepted' => 2]);

        $this->withToken('test-interfaceh-token')
            ->postJson('/api/personnel', [
                'source' => 'attendance',
                'data' => [[
                    'id' => 10,
                    'personCode' => '0010',
                    'name' => 'María',
                    'last_name' => 'Pérez Actualizada',
                    'complete_name' => 'María Pérez Actualizada',
                    'group_id' => 3,
                ]],
            ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'accepted' => 1]);

        $this->assertDatabaseCount('integration_records', 2);
        $this->assertSame(
            'María Pérez Actualizada',
            json_decode(DB::table('integration_records')->where('external_id', '10')->value('payload'), true)['complete_name'],
        );
        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseHas('people', [
            'employee_number' => '0010',
            'name' => 'María Pérez Actualizada',
            'department' => null,
            'hikcentral_id' => null,
            'active' => true,
        ]);
        $this->assertDatabaseHas('people', ['employee_number' => '0011', 'name' => 'Juan López']);
    }

    public function test_resource_and_source_are_part_of_the_idempotency_key(): void
    {
        foreach ([
            ['source' => 'attendance', 'data' => [['id' => 1, 'personCode' => '001', 'name' => 'Persona', 'complete_name' => 'Persona']]],
            ['source' => 'attendance', 'data' => [['id' => 1, 'name' => 'Dispositivo']]],
            ['source' => 'second-source', 'data' => [['id' => 1, 'personCode' => '001', 'name' => 'Persona', 'complete_name' => 'Persona']]],
        ] as $index => $payload) {
            $resource = $index === 1 ? 'devices' : 'personnel';
            $this->withToken('test-interfaceh-token')
                ->postJson('/api/'.$resource, $payload)
                ->assertOk();
        }

        $this->assertDatabaseCount('integration_records', 3);
    }

    public function test_personnel_import_updates_name_without_overwriting_local_person_data(): void
    {
        Person::create([
            'employee_number' => '0025',
            'hikcentral_id' => 'hik-person-25',
            'name' => 'Nombre anterior',
            'department' => 'Operaciones',
            'active' => false,
        ]);

        $this->withToken('test-interfaceh-token')
            ->postJson('/api/personnel', [
                'source' => 'attendance',
                'data' => [['id' => 25, 'personCode' => '0025', 'complete_name' => 'Nombre sincronizado']],
            ])
            ->assertOk();

        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseHas('people', [
            'employee_number' => '0025',
            'hikcentral_id' => 'hik-person-25',
            'name' => 'Nombre sincronizado',
            'department' => 'Operaciones',
            'active' => false,
        ]);
    }

    public function test_groups_are_imported_with_hierarchy_and_linked_to_people(): void
    {
        $this->withToken('test-interfaceh-token')
            ->postJson('/api/personnel', [
                'source' => 'attendance',
                'data' => [['id' => 5, 'personCode' => '005', 'complete_name' => 'Ana Ruiz', 'group_id' => 2]],
            ])
            ->assertOk();
        $this->assertDatabaseHas('groups', ['source' => 'attendance', 'external_id' => 2, 'name' => 'Grupo 2']);

        $this->withToken('test-interfaceh-token')
            ->postJson('/api/groups', [
                'source' => 'attendance',
                'data' => [
                    ['id' => 2, 'name' => 'CMC', 'parent_id' => 1],
                    ['id' => 1, 'name' => 'Corporativo', 'parent_id' => null],
                ],
            ])
            ->assertOk()
            ->assertExactJson(['success' => true, 'accepted' => 2]);

        $this->assertDatabaseCount('groups', 2);
        $root = Group::where('external_id', 1)->firstOrFail();
        $child = Group::where('external_id', 2)->firstOrFail();
        $this->assertSame('Corporativo', $root->name);
        $this->assertNull($root->parent_id);
        $this->assertSame('CMC', $child->name);
        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame($child->id, Person::where('employee_number', '005')->value('group_id'));

        $this->withToken('test-interfaceh-token')
            ->postJson('/api/personnel', [
                'source' => 'attendance',
                'data' => [['id' => 5, 'personCode' => '005', 'complete_name' => 'Ana Ruiz', 'group_id' => null]],
            ])
            ->assertOk();
        $this->assertNull(Person::where('employee_number', '005')->value('group_id'));
    }

    public function test_people_in_baja_group_are_deactivated_and_reactivated_when_they_leave_it(): void
    {
        $send = fn (string $resource, array $data) => $this->withToken('test-interfaceh-token')
            ->postJson('/api/'.$resource, ['source' => 'attendance', 'data' => $data])->assertOk();

        $send('groups', [['id' => 1, 'name' => 'Planta'], ['id' => 32, 'name' => 'BAJAS']]);
        $send('personnel', [
            ['id' => 7, 'personCode' => '007', 'complete_name' => 'Baja Uno', 'group_id' => 32],
            ['id' => 8, 'personCode' => '008', 'complete_name' => 'Activo Uno', 'group_id' => 1],
        ]);
        $this->assertFalse(Person::where('employee_number', '007')->value('active'));
        $this->assertTrue(Person::where('employee_number', '008')->value('active'));

        $send('personnel', [['id' => 7, 'personCode' => '007', 'complete_name' => 'Baja Uno', 'group_id' => 1]]);
        $this->assertTrue(Person::where('employee_number', '007')->value('active'));

        $send('groups', [['id' => 1, 'name' => 'Baja']]);
        $this->assertSame(0, Person::where('active', true)->count());
    }

    public function test_groups_require_a_name(): void
    {
        $this->withToken('test-interfaceh-token')
            ->postJson('/api/groups', ['source' => 'attendance', 'data' => [['id' => 1, 'parent_id' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.0.name', 'data.0.parent_id']);
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_devices_are_imported_without_overwriting_local_isapi_configuration(): void
    {
        $device = fn (array $overrides = []) => ['source' => 'attendance', 'data' => [$overrides + [
            'id' => 8, 'name' => 'VMC-TOPIA', 'ip_address' => '10.13.118.11', 'port' => 8000,
            'code' => 'DS-K1T671TM-3XF20240702V030315ENFR3067658', 'type' => 'hiksdk_net', 'status' => 1,
        ]]];

        $this->withToken('test-interfaceh-token')->postJson('/api/devices', $device())->assertOk();
        $this->assertDatabaseHas('devices', [
            'source' => 'attendance', 'external_id' => 8, 'name' => 'VMC-TOPIA', 'model' => 'DS-K1T671TM-3XF',
            'ip_address' => '10.13.118.11', 'port' => 8000, 'online' => true, 'base_url' => null,
            'read_enabled' => false, 'write_enabled' => false,
        ]);

        Device::where('external_id', 8)->firstOrFail()->update([
            'base_url' => 'https://10.13.118.11', 'username' => 'admin', 'password' => 'device-secret',
            'location' => 'Topia', 'direction' => 'entry', 'read_enabled' => true,
        ]);
        $this->withToken('test-interfaceh-token')->postJson('/api/devices', $device(['name' => 'VMC-TOPIA-2', 'status' => 0]))->assertOk();

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseHas('devices', [
            'name' => 'VMC-TOPIA-2', 'online' => false, 'base_url' => 'https://10.13.118.11',
            'location' => 'Topia', 'direction' => 'entry', 'read_enabled' => true,
        ]);
        $this->assertSame('device-secret', Device::firstOrFail()->password);
    }

    public function test_api_rejects_missing_or_invalid_tokens_without_storing_data(): void
    {
        $payload = ['source' => 'attendance', 'data' => [['id' => 1, 'name' => 'Persona']]];

        $this->postJson('/api/personnel', $payload)->assertUnauthorized();
        $this->withToken('wrong-token')->postJson('/api/personnel', $payload)->assertUnauthorized();
        $this->assertDatabaseCount('integration_records', 0);
    }

    public function test_api_requires_a_configured_token_and_valid_record_ids(): void
    {
        DB::table('interfaceh_api_tokens')->delete();
        $this->withToken('test-interfaceh-token')
            ->postJson('/api/connection', ['source' => 'attendance'])
            ->assertServiceUnavailable();

        DB::table('interfaceh_api_tokens')->insert([
            'id' => 1,
            'token_hash' => hash('sha256', 'test-interfaceh-token'),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withToken('test-interfaceh-token')
            ->postJson('/api/personnel', [
                'source' => 'attendance',
                'data' => [['id' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.0.personCode', 'data.0.complete_name']);
        $this->assertDatabaseCount('integration_records', 0);
        $this->assertDatabaseCount('people', 0);
    }
}
