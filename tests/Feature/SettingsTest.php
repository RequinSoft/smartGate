<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\Movement;
use App\Models\User;
use App\Services\MovementQuery;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Settings::flush();
    }

    public function test_configuration_menu_groups_backups_smtp_and_timezone(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');

        $this->assertSame(['backups', 'smtp', 'timezone'], array_values(Workspace::menuGroupsFor($admin)['Configuración']));
        $this->assertContains('devices', Workspace::menuGroupsFor($admin)['Administrativos']);
        $this->actingAs($admin)->get('/smtp')->assertOk();
        $this->actingAs($admin)->get('/timezone')->assertOk();
    }

    public function test_operators_cannot_manage_settings(): void
    {
        $operator = User::factory()->create()->assignRole('Operador');

        $this->actingAs($operator)->get('/smtp')->assertForbidden();
        $this->actingAs($operator)->get('/timezone')->assertForbidden();
    }

    public function test_smtp_settings_are_saved_with_encrypted_password_and_applied(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');

        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'smtp'])
            ->set('smtpHost', 'smtp.example.com')->set('smtpPort', '465')->set('smtpScheme', 'smtps')
            ->set('smtpUsername', 'mailer')->set('smtpPassword', 'smtp-super-secret')
            ->set('smtpFromAddress', 'noreply@example.com')->set('smtpFromName', 'SmartGate')
            ->call('saveSmtp')->assertHasNoErrors()->assertSet('smtpPassword', '')->assertSet('smtpPasswordSet', true);

        $stored = DB::table('settings')->where('key', 'smtp_password')->value('value');
        $this->assertNotSame('smtp-super-secret', $stored);
        $this->assertSame('smtp-super-secret', Crypt::decryptString($stored));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
        $this->assertStringNotContainsString('smtp-super-secret', DB::table('activity_log')->get()->toJson());

        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'smtp'])
            ->set('smtpFromName', 'Otro nombre')->call('saveSmtp')->assertHasNoErrors();
        $this->assertSame($stored, DB::table('settings')->where('key', 'smtp_password')->value('value'));
    }

    public function test_timezone_is_validated_and_used_for_display_and_day_filters(): void
    {
        $admin = User::factory()->create()->assignRole('Administrador');

        Livewire::actingAs($admin)->test(Workspace::class, ['section' => 'timezone'])
            ->set('timezone', 'Mars/Olympus')->call('saveTimezone')->assertHasErrors('timezone')
            ->set('timezone', 'America/Mexico_City')->call('saveTimezone')->assertHasNoErrors();

        $this->assertSame('America/Mexico_City', Settings::timezone());
        $this->assertSame('America/Mexico_City', config('app.schedule_timezone'));
        $this->assertSame('2026-01-01 18:00:00', Settings::local('2026-01-02 00:00:00'));

        $sql = MovementQuery::build(['from' => '2026-01-01', 'to' => '2026-01-01']);
        $bindings = array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value, $sql->getBindings());
        $this->assertSame(['2026-01-01 06:00:00', '2026-01-02 06:00:00'], $bindings);
        $this->assertInstanceOf(Movement::class, $sql->getModel());
    }
}
