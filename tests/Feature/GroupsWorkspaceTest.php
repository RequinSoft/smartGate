<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Group;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_browse_and_search_groups_with_hierarchy_and_people_counts(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        $parent = Group::create(['source' => 'interfaceh', 'external_id' => 1, 'name' => 'Corporativo']);
        $child = Group::create(['source' => 'interfaceh', 'external_id' => 32, 'name' => 'BAJAS', 'parent_id' => $parent->id]);
        Person::create(['employee_number' => '001', 'name' => 'Persona de prueba', 'group_id' => $child->id, 'active' => false]);

        $this->assertContains('groups', Workspace::menuGroupsFor($admin)['Administrativos']);
        $this->actingAs($admin)->get('/groups')->assertOk()->assertSee('Buscar grupo')->assertSee('BAJAS');
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'groups'])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2)
            ->set('search', 'BAJAS')->assertSee('Corporativo')->assertSee('interfaceh')
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $child->id && $rows->first()->people_count === 1)
            ->set('search', 'No existe')->assertSee('No hay grupos para mostrar.');
    }

    public function test_groups_require_authentication_and_integration_permission(): void
    {
        $this->get('/groups')->assertRedirect('/login');
        $operator = User::factory()->create()->assignRole('Operador');
        $this->assertNotContains('groups', array_merge(...array_values(Workspace::menuGroupsFor($operator))));
        $this->actingAs($operator)->get('/groups')->assertForbidden();
    }
}
