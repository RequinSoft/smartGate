<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_every_page_requires_authentication(): void
    {
        foreach (array_keys(Workspace::SECTIONS) as $section) {
            $this->get('/'.$section)->assertRedirect('/login');
        }
        $this->get('/reports/movements.csv')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
    }

    public function test_admin_can_render_all_sections(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        foreach (array_keys(Workspace::SECTIONS) as $section) {
            $this->actingAs($admin)->get('/'.$section)->assertOk();
        }
    }

    public function test_auditor_cannot_modify_personnel_through_livewire(): void
    {
        $auditor = User::factory()->create()->assignRole('Auditor');
        $this->actingAs($auditor)->get('/people')->assertForbidden();
        Livewire::actingAs($auditor)->test(Workspace::class, ['section' => 'movements'])
            ->set('name', 'Intruso')->set('number', '123')->call('savePerson')->assertForbidden();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_operator_cannot_export_or_manage_users(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $this->actingAs($operator)->get('/users')->assertForbidden();
        $this->get('/reports/movements.csv?from=2026-10-01&to=2026-10-15')->assertForbidden();
    }

    public function test_login_and_logout_are_audited_without_passwords(): void
    {
        $user = User::factory()->create(['password' => 'a-secure-password'])->assignRole('Administrador');
        $this->post('/login', ['email' => $user->email, 'password' => 'a-secure-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(2, Activity::whereIn('description', ['auth.login', 'auth.logout'])->count());
        $this->assertStringNotContainsString('a-secure-password', Activity::all()->toJson());
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'wrong@example.com', 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->assertSame(5, Activity::where('description', 'auth.failed')->count());
    }

    public function test_person_vehicle_user_and_device_forms_persist_and_audit(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'people'])
            ->set('name', 'María Pérez')->set('number', '00123')->set('department', 'Operaciones')
            ->call('savePerson')->assertHasNoErrors();
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'vehicles'])
            ->set('plate', 'abc-123')->set('description', 'Camioneta')->call('saveVehicle')->assertHasNoErrors();
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'devices'])
            ->set('name', 'Acceso norte')->set('model', 'DS-K1T341CMFW')->set('baseUrl', 'https://192.0.2.10')
            ->set('username', 'device-user')->set('password', 'device-super-secret')->set('location', 'Norte')
            ->call('saveDevice')->assertHasNoErrors();
        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'users'])
            ->set('name', 'Auditor Uno')->set('email', 'auditor@example.com')->set('password', 'new-secure-password')
            ->set('role', 'Auditor')->call('saveUser')->assertHasNoErrors();
        $this->assertDatabaseHas('people', ['employee_number' => '00123']);
        $this->assertDatabaseHas('vehicles', ['plate' => 'ABC-123']);
        $this->assertTrue(User::where('email', 'auditor@example.com')->firstOrFail()->hasRole('Auditor'));
        $json = Activity::all()->toJson();
        $this->assertStringNotContainsString('device-super-secret', $json);
        $this->assertStringNotContainsString('new-secure-password', $json);
        $this->assertStringNotContainsString('device-user', $json);
        $this->assertDatabaseHas('devices', ['read_enabled' => false, 'write_enabled' => false]);
    }

    public function test_person_modal_create_and_edit_flow(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        $component = Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'people'])
            ->assertDontSee('person-modal-title')
            ->call('createPerson')->assertSet('showPersonModal', true)->assertSee('Añadir persona')
            ->call('savePerson')->assertHasErrors(['name', 'number'])->assertSet('showPersonModal', true)
            ->set('name', 'Ana Ruiz')->set('number', 'E-1')->call('savePerson')
            ->assertHasNoErrors()->assertSet('showPersonModal', false);
        $person = Person::where('employee_number', 'E-1')->firstOrFail();
        $component->call('editPerson', $person->id)->assertSet('showPersonModal', true)->assertSet('name', 'Ana Ruiz')
            ->set('name', 'Ana Ruiz Gómez')->call('savePerson')->assertSet('showPersonModal', false);
        $this->assertSame('Ana Ruiz Gómez', $person->fresh()->name);
        $component->call('createPerson')->call('closePersonModal')->assertSet('showPersonModal', false)->assertSet('name', '');
    }
}
