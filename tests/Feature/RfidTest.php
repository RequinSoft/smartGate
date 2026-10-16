<?php

namespace Tests\Feature;

use App\Jobs\ProvisionRfid;
use App\Models\Device;
use App\Models\Person;
use App\Models\Provisioning;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\IsapiClient;
use App\Services\RecordMovement;
use App\Services\RfidService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RfidTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Person $person;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create()->assignRole('Administrador');
        $this->actingAs($this->actor);
        $this->person = Person::create(['employee_number' => '001', 'name' => 'Persona Uno']);
        $this->device = Device::create([
            'name' => 'Entrada', 'model' => 'DS-K1T341CMFW', 'base_url' => 'https://192.0.2.10',
            'username' => 'admin', 'password' => 'secret', 'location' => 'Puerta norte',
            'direction' => 'entry', 'read_enabled' => true, 'write_enabled' => true,
        ]);
    }

    public function test_tag_is_unique_and_preserves_leading_zeroes(): void
    {
        $assignment = app(RfidService::class)->assign('000123', $this->person->id, null, [], $this->actor);
        $this->assertSame('000123', $assignment->tag->number);
        $this->expectException(ValidationException::class);
        app(RfidService::class)->assign('000123', $this->person->id, null, [], $this->actor);
    }

    public function test_revocation_blocks_reuse_until_remote_deletion_confirmed(): void
    {
        $service = app(RfidService::class);
        $assignment = $service->assign('123', $this->person->id, null, [$this->device->id], $this->actor);
        $service->revoke($assignment->id);
        $this->assertNotNull($assignment->fresh()->revoked_at);
        $this->assertDatabaseHas('provisionings', ['operation' => 'delete', 'status' => 'pending']);
        Queue::assertPushed(ProvisionRfid::class, 2);
        $this->expectException(ValidationException::class);
        $service->assign('123', $this->person->id, null, [], $this->actor);
    }

    public function test_remote_write_confirms_status_and_cancelled_high_does_not_reactivate_card(): void
    {
        $this->fakeDevice();
        $service = app(RfidService::class);
        $assignment = $service->assign('123', $this->person->id, null, [$this->device->id], $this->actor);
        $upsert = Provisioning::firstOrFail();
        (new ProvisionRfid($upsert->id))->handle(app(IsapiClient::class));
        $this->assertSame('applied', $upsert->fresh()->status);
        Http::assertSentCount(6);
        $service->revoke($assignment->id);
        $delete = Provisioning::where('operation', 'delete')->firstOrFail();
        (new ProvisionRfid($delete->id))->handle(app(IsapiClient::class));
        $this->assertSame('applied', $delete->fresh()->status);
        (new ProvisionRfid($upsert->id))->handle(app(IsapiClient::class));
        Http::assertSentCount(10);
        $service->assign('123', $this->person->id, null, [], $this->actor);
        $this->assertDatabaseCount('rfid_assignments', 2);
    }

    public function test_http_success_with_device_error_is_not_applied(): void
    {
        $this->fakeDevice(writeStatus: 4);
        app(RfidService::class)->assign('123', $this->person->id, null, [$this->device->id], $this->actor);
        $item = Provisioning::firstOrFail();
        try {
            (new ProvisionRfid($item->id))->handle(app(IsapiClient::class));
            $this->fail('La escritura remota debió fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no confirmó', $exception->getMessage());
        }
        $this->assertSame('failed', $item->fresh()->status);
    }

    public function test_history_matches_event_time_not_current_owner_and_events_are_idempotent(): void
    {
        $service = app(RfidService::class);
        $vehicle = Vehicle::create(['plate' => 'AAA-001', 'description' => 'Vehículo uno']);
        $this->travelTo(now()->startOfSecond());
        $assignment = $service->assign('000123', $this->person->id, $vehicle->id, [], $this->actor);
        $eventTime = now()->addMinute()->toIso8601String();
        $this->travel(2)->minutes();
        $service->revoke($assignment->id);
        $secondPerson = Person::create(['employee_number' => '002', 'name' => 'Persona Dos']);
        $service->assign('000123', $secondPerson->id, null, [], $this->actor);
        $row = ['serialNo' => 15, 'time' => $eventTime, 'major' => 5, 'minor' => 1, 'cardNo' => '000123', 'employeeNoString' => '001'];
        $recorder = app(RecordMovement::class);
        $first = $recorder->record($this->device, $row);
        $second = $recorder->record($this->device, $row);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->person->id, $first->person_id);
        $this->assertSame($vehicle->id, $first->vehicle_id);
        $this->assertDatabaseCount('movements', 1);
    }

    public function test_unknown_card_does_not_fall_back_to_current_employee(): void
    {
        $movement = app(RecordMovement::class)->record($this->device, [
            'serialNo' => 1, 'time' => now()->toIso8601String(), 'major' => 5, 'minor' => 1,
            'cardNo' => 'unknown', 'employeeNoString' => $this->person->employee_number,
        ]);
        $this->assertNull($movement->person_id);
    }

    public function test_card_is_not_sent_when_employee_is_missing_from_device(): void
    {
        Http::fake(fn ($request) => $request->hasHeader('Authorization')
            ? Http::response(str_contains($request->url(), 'CardInfo/Search')
                ? ['CardInfoSearch' => ['numOfMatches' => 0]]
                : ['UserInfoSearch' => ['numOfMatches' => 0, 'UserInfo' => []]])
            : Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="test", nonce="nonce", qop="auth", algorithm=MD5']));
        $assignment = app(RfidService::class)->assign('999', $this->person->id, null, [], $this->actor);
        try {
            app(IsapiClient::class)->provision($this->device, $assignment, 'upsert');
            $this->fail('Un usuario ausente debió impedir el envío de la tarjeta.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No se modificaron permisos', $exception->getMessage());
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'CardInfo/SetUp'));
    }

    public function test_remote_card_owned_by_another_employee_cannot_be_overwritten_or_deleted(): void
    {
        $this->fakeDevice(remoteEmployee: 'OTHER');
        $assignment = app(RfidService::class)->assign('123', $this->person->id, null, [], $this->actor);
        foreach (['upsert', 'delete'] as $operation) {
            try {
                app(IsapiClient::class)->provision($this->device, $assignment, $operation);
                $this->fail('Una tarjeta de otro empleado no debe modificarse.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('no coincide', $exception->getMessage());
            }
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    private function fakeDevice(int $writeStatus = 1, string $remoteEmployee = '001'): void
    {
        Http::fake(function ($request) use ($writeStatus, $remoteEmployee) {
            if (! $request->hasHeader('Authorization')) {
                return Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="test", nonce="nonce", qop="auth", algorithm=MD5']);
            }
            if (str_contains($request->url(), 'CardInfo/Search')) {
                return Http::response(['CardInfoSearch' => ['numOfMatches' => 1, 'CardInfo' => [['employeeNo' => $remoteEmployee, 'cardNo' => '123']]]]);
            }
            if (str_contains($request->url(), 'UserInfo/Search')) {
                return Http::response(['UserInfoSearch' => ['numOfMatches' => 1, 'UserInfo' => [['employeeNo' => '001']]]]);
            }

            return Http::response(['statusCode' => $writeStatus]);
        });
    }
}
