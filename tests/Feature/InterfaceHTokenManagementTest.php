<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class InterfaceHTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_administrator_can_generate_a_token_that_is_shown_once_and_authenticates_the_api(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        $component = Livewire::actingAs($admin)
            ->test(Workspace::class, ['section' => 'interfaceh'])
            ->assertSee('Sin token activo')
            ->call('generateInterfaceHToken')
            ->assertHasNoErrors()
            ->assertSee('Token generado.');

        $token = $component->get('generatedInterfaceHToken');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), DB::table('interfaceh_api_tokens')->value('token_hash'));
        $this->assertStringNotContainsString($token, Activity::all()->toJson());

        Livewire::actingAs($admin)
            ->test(Workspace::class, ['section' => 'interfaceh'])
            ->assertSee('Token activo')
            ->assertDontSee($token);

        $this->withToken($token)
            ->postJson('/api/connection', ['source' => 'interfaceH'])
            ->assertOk()
            ->assertExactJson(['success' => true]);
    }

    public function test_administrator_can_revoke_and_rotate_the_api_token(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        $component = Livewire::actingAs($admin)
            ->test(Workspace::class, ['section' => 'interfaceh'])
            ->call('generateInterfaceHToken');
        $oldToken = $component->get('generatedInterfaceHToken');

        $component->call('revokeInterfaceHToken')
            ->assertSet('generatedInterfaceHToken', '')
            ->assertSee('Token revocado.');
        $this->withToken($oldToken)
            ->postJson('/api/connection', ['source' => 'interfaceH'])
            ->assertServiceUnavailable();

        $newToken = Livewire::actingAs($admin)
            ->test(Workspace::class, ['section' => 'interfaceh'])
            ->call('generateInterfaceHToken')
            ->get('generatedInterfaceHToken');

        $this->assertNotSame($oldToken, $newToken);
        $this->withToken($newToken)
            ->postJson('/api/connection', ['source' => 'interfaceH'])
            ->assertOk();
        $this->withToken($oldToken)
            ->postJson('/api/connection', ['source' => 'interfaceH'])
            ->assertUnauthorized();
    }

    public function test_only_users_with_integration_permission_can_manage_interfaceh_tokens(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');

        $this->actingAs($operator)->get('/interfaceh')->assertForbidden();
        Livewire::actingAs($operator)
            ->test(Workspace::class, ['section' => 'interfaceh'])
            ->call('generateInterfaceHToken')
            ->assertForbidden();
        $this->assertDatabaseCount('interfaceh_api_tokens', 0);
    }

    public function test_navigation_shows_separate_operational_and_administrative_blocks(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');
        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Operativos')
            ->assertSee('Administrativos')
            ->assertSee('API interfazH')
            ->assertSee('summary class="nav-title"', false)
            ->assertSee('class="nav-group" open', false)
            ->assertSee('class="nav-links"', false)
            ->assertSee('class="nav-icon"', false);

        $operator = User::factory()->create()->assignRole('Operador');
        $this->actingAs($operator)->get('/dashboard')
            ->assertOk()
            ->assertSee('Operativos')
            ->assertDontSee('Administrativos')
            ->assertDontSee('API interfazH');
    }
}
