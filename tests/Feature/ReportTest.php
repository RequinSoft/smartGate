<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Livewire\Workspace;
use App\Models\Device;
use App\Models\Movement;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_full_end_day_and_escapes_formulas(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create()->assignRole('Auditor');
        $device = Device::create(['name' => '=danger', 'model' => 'Test', 'base_url' => 'https://192.0.2.30', 'username' => 'u', 'password' => 'p', 'location' => 'Norte']);
        foreach (['2026-10-15 23:59:59', '2026-10-16 00:00:00'] as $index => $time) {
            Movement::create(['device_id' => $device->id, 'external_id' => (string) $index, 'event_type' => '5:1', 'direction' => 'unknown', 'location' => 'Norte', 'occurred_at' => $time, 'received_at' => now(), 'payload' => []]);
        }
        $response = $this->actingAs($user)->get('/reports/movements.csv?from=2026-10-15&to=2026-10-15')->assertOk()->assertDownload('movimientos.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('2026-10-15 23:59:59', $csv);
        $this->assertStringNotContainsString('2026-10-16', $csv);
        $this->assertStringContainsString("'=danger", $csv);
        $this->assertDatabaseHas('activity_log', ['description' => 'report.export']);
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create()->assignRole('Auditor');
        $this->actingAs($user)->getJson('/reports/movements.csv?from=2026-10-16&to=2026-10-15')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_csv_cells_with_whitespace_before_formulas_are_escaped(): void
    {
        $this->assertSame("' \t=cmd()", ReportController::safeCell(" \t=cmd()"));
        $this->assertSame('000123', ReportController::safeCell('000123'));
    }

    public function test_invalid_livewire_filters_show_errors_without_breaking_the_page(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create()->assignRole('Auditor');
        Livewire::actingAs($user)->test(Workspace::class, ['section' => 'movements'])
            ->set('from', '2026-10-16')->set('to', '2026-10-15')
            ->call('applyFilters')->assertHasErrors('to')
            ->assertSee('No se pudo completar la operación.');
    }
}
