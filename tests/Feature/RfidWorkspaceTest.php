<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Person;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RfidWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Queue::fake();
    }

    public function test_rfid_catalog_registration_is_separate_from_assignments(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $this->assertContains('rfid-tags', Workspace::menuGroupsFor($operator)['Operativos']);
        $this->assertContains('rfid', Workspace::menuGroupsFor($operator)['Operativos']);
        $component = Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'rfid-tags'])
            ->assertDontSee('rfid-modal-title')
            ->call('createRfid')->assertSee('rfid-modal-title')->assertSet('showRfidModal', true)
            ->set('number', 'ABC')->call('saveRfid')->assertHasErrors('number')->assertSet('showRfidModal', true)
            ->set('number', '000123')->call('saveRfid')->assertHasNoErrors()
            ->assertSet('showRfidModal', false)->assertSet('number', '')->assertSee('000123');
        $this->assertDatabaseHas('rfid_tags', ['number' => '000123']);
        $this->assertDatabaseCount('rfid_assignments', 0);
        $this->assertDatabaseCount('provisionings', 0);
        Queue::assertNothingPushed();
        $component->call('createRfid')->set('number', '000123')->call('saveRfid')->assertHasErrors('number')
            ->call('closeRfidModal')->assertHasNoErrors()->assertSet('showRfidModal', false)->assertSet('number', '')
            ->set('search', '999')->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
    }

    public function test_assignment_modal_uses_registered_rfid_and_preserves_history_and_revocation(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');
        $person = Person::create(['employee_number' => 'E-1', 'name' => 'Persona RFID']);
        RfidTag::create(['number' => '000123']);
        $component = Livewire::actingAs($operator)->test(Workspace::class, ['section' => 'rfid'])
            ->assertDontSee('assignment-modal-title')
            ->call('createAssignment')->assertSet('showAssignmentModal', true)->assertSee('000123')
            ->set('number', '999')->set('personId', (string) $person->id)->call('assign')->assertHasErrors('number')
            ->assertSet('showAssignmentModal', true)
            ->set('number', '000123')->call('assign')->assertHasNoErrors()
            ->assertSet('showAssignmentModal', false)->assertSee('Persona RFID')->assertSee('Activa');
        $this->assertDatabaseCount('rfid_tags', 1);
        $this->assertDatabaseCount('rfid_assignments', 1);
        $assignmentId = RfidAssignment::firstOrFail()->id;
        $component->call('createAssignment')->set('number', '000123')->set('personId', (string) $person->id)
            ->call('assign')->assertHasErrors('number')->assertSet('showAssignmentModal', true)
            ->call('closeAssignmentModal')->assertHasNoErrors()->assertSet('number', '')->assertSet('personId', '')
            ->call('revoke', $assignmentId)->assertHasNoErrors();
        $this->assertDatabaseMissing('rfid_assignments', ['id' => $assignmentId, 'revoked_at' => null]);
    }

    public function test_rfid_catalog_and_assignment_modal_require_rfid_permission(): void
    {
        $auditor = User::factory()->create()->assignRole('Auditor');
        $this->actingAs($auditor)->get('/rfid-tags')->assertForbidden();
        $this->get('/rfid')->assertForbidden();
        foreach (['createRfid', 'saveRfid', 'createAssignment', 'assign'] as $action) {
            Livewire::actingAs($auditor)->test(Workspace::class, ['section' => 'movements'])
                ->call($action)->assertForbidden();
        }
        $this->assertDatabaseCount('rfid_tags', 0);
    }
}
