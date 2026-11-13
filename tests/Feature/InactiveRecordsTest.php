<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Person;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InactiveRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_menu_exposes_inactive_sections_with_existing_permissions(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $this->assertSame(
            ['inactive-people', 'inactive-vehicles'],
            array_values(Workspace::menuGroupsFor($operator)['Inactivos']),
        );
        $this->actingAs($operator)->get('/inactive-people')->assertOk()->assertSee('Personal inactivo');
        $this->get('/inactive-vehicles')->assertOk()->assertSee('Vehículos inactivos');

        $auditor = User::factory()->create()->assignRole('Auditor');
        $this->assertArrayNotHasKey('Inactivos', Workspace::menuGroupsFor($auditor));
        $this->actingAs($auditor)->get('/inactive-people')->assertForbidden();
        $this->get('/inactive-vehicles')->assertForbidden();
    }

    public function test_dashboard_counts_only_active_people_and_vehicles(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        Person::create(['employee_number' => 'ON-001', 'name' => 'Activo', 'active' => true]);
        Person::create(['employee_number' => 'OFF-001', 'name' => 'Inactivo', 'active' => false]);
        Vehicle::create(['plate' => 'ON-001', 'description' => 'Activo', 'active' => true]);
        Vehicle::create(['plate' => 'OFF-001', 'description' => 'Inactivo', 'active' => false]);

        $this->actingAs($operator)->get('/dashboard')->assertOk()
            ->assertSee('<span>Personal activo</span><strong>1</strong>', false)
            ->assertSee('<span>Vehículos activos</span><strong>1</strong>', false);

        Person::where('active', true)->update(['active' => false]);
        Vehicle::where('active', true)->update(['active' => false]);
        $this->get('/dashboard')->assertOk()
            ->assertSee('<span>Personal activo</span><strong>0</strong>', false)
            ->assertSee('<span>Vehículos activos</span><strong>0</strong>', false);
    }

    public function test_personnel_lists_and_search_keep_active_and_inactive_people_separate(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $active = Person::create(['employee_number' => 'ON-001', 'name' => 'Persona activa', 'active' => true]);
        $inactive = Person::create(['employee_number' => 'OFF-001', 'name' => 'Persona inactiva', 'active' => false]);

        foreach (['people' => $active, 'inactive-people' => $inactive] as $section => $expected) {
            $component = Livewire::actingAs($operator)->test(Workspace::class, ['section' => $section])
                ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$expected->id])
                ->set('search', '001')
                ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$expected->id]);
            $component->set('search', $expected->active ? 'OFF-001' : 'ON-001')
                ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        }

        Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'inactive-people'])
            ->assertDontSeeHtml('wire:click="createPerson"')
            ->call('editPerson', $inactive->id)->assertSet('active', false)
            ->set('active', true)->call('savePerson')->assertHasNoErrors()
            ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        $this->assertTrue($inactive->fresh()->active);
    }

    public function test_vehicle_lists_support_deactivation_and_reactivation(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $details = ['brand' => 'Toyota', 'model' => 'Hilux', 'version' => 'SR', 'color' => 'Blanco', 'ownership' => 'company', 'description' => 'Camioneta'];
        $active = Vehicle::create($details + ['plate' => 'ACT-001']);
        $inactive = Vehicle::create($details + ['plate' => 'INA-001', 'active' => false]);
        $this->assertTrue($active->fresh()->active);

        foreach (['vehicles' => $active, 'inactive-vehicles' => $inactive] as $section => $expected) {
            Livewire::actingAs($operator)->test(Workspace::class, ['section' => $section])
                ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$expected->id])
                ->set('search', '001')
                ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$expected->id])
                ->set('search', $expected->plate === 'ACT-001' ? 'INA-001' : 'ACT-001')
                ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        }

        Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'vehicles'])
            ->call('editVehicle', $active->id)->assertSet('active', true)
            ->set('active', false)->call('saveVehicle')->assertHasNoErrors()
            ->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
        $this->assertFalse($active->fresh()->active);

        Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'inactive-vehicles'])
            ->assertDontSeeHtml('wire:click="createVehicle"')
            ->call('editVehicle', $inactive->id)->assertSet('active', false)
            ->set('active', true)->call('saveVehicle')->assertHasNoErrors()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$active->id]);
        $this->assertTrue($inactive->fresh()->active);
    }

    public function test_vehicle_ownership_is_shown_in_both_lists_and_legacy_records_can_be_classified(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $details = ['brand' => 'Toyota', 'model' => 'Hilux', 'version' => 'SR', 'color' => 'Blanco', 'description' => 'Camioneta'];
        $legacy = Vehicle::create($details + ['plate' => 'OLD-001']);
        Vehicle::create($details + ['plate' => 'PER-001', 'ownership' => 'personal']);
        Vehicle::create($details + ['plate' => 'EMP-001', 'ownership' => 'company', 'active' => false]);

        Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'vehicles'])
            ->assertSee('Sin clasificar')->assertSeeHtml('<td>Personal</td>')
            ->call('editVehicle', $legacy->id)->assertSet('vehicleOwnership', '')
            ->set('vehicleOwnership', 'company')->call('saveVehicle')->assertHasNoErrors()
            ->assertSeeHtml('<td>De la empresa</td>')
            ->call('createVehicle')->assertSet('vehicleOwnership', '');
        $this->assertSame('company', $legacy->fresh()->ownership);

        Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'inactive-vehicles'])
            ->assertSeeHtml('<td>De la empresa</td>');
    }
}
