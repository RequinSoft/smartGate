<?php

namespace Tests\Feature;

use App\Jobs\PollDevice;
use App\Jobs\RunBackup;
use App\Models\Device;
use App\Services\HikCentralClient;
use App\Services\ImportPeople;
use App\Services\IsapiClient;
use App\Services\RecordMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['hikvision.hikcentral.url' => 'https://hikcentral.example', 'hikvision.hikcentral.key' => 'key', 'hikvision.hikcentral.secret' => 'secret']);
    }

    public function test_hikcentral_signature_and_import_are_idempotent(): void
    {
        Http::fake(['*' => Http::response(['code' => '0', 'data' => [
            'total' => 1, 'list' => [['personId' => 'external-id', 'personCode' => '0001', 'personName' => 'María Pérez', 'orgIndexCode' => 'org-1']],
        ]])]);
        $import = app(ImportPeople::class);
        $this->assertSame(1, $import->run());
        $this->assertSame(1, $import->run());
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseHas('people', ['employee_number' => '0001', 'hikcentral_id' => 'external-id', 'name' => 'María Pérez']);
        $canonical = "POST\n*/*\napplication/json\nx-ca-key:key\n/artemis/api/resource/v1/person/personList";
        Http::assertSent(fn ($request) => $request->hasHeader('x-ca-signature', base64_encode(hash_hmac('sha256', $canonical, 'secret', true)))
            && $request['pageNo'] === 1 && $request['pageSize'] === 100);
    }

    public function test_hikcentral_error_is_not_treated_as_success(): void
    {
        Http::fake(['*' => Http::response(['code' => 'error', 'data' => []])]);
        $this->expectException(\RuntimeException::class);
        app(HikCentralClient::class)->post('/test', []);
    }

    public function test_import_does_not_guess_person_id_from_employee_code(): void
    {
        Http::fake(['*' => Http::response(['code' => '0', 'data' => [
            'total' => 1, 'list' => [['personCode' => '0001', 'personName' => 'Persona']],
        ]])]);
        $this->expectException(ValidationException::class);
        app(ImportPeople::class)->run();
    }

    public function test_isapi_poll_paginates_and_only_advances_after_completion(): void
    {
        $device = $this->device();
        $event = ['serialNo' => 1, 'time' => now()->subMinute()->toIso8601String(), 'major' => 5, 'minor' => 1, 'cardNo' => '123'];
        Http::fake(['*' => Http::sequence()
            ->push('', 401, ['WWW-Authenticate' => 'Digest realm="test", nonce="nonce", qop="auth", algorithm=MD5'])
            ->push(['AcsEvent' => ['totalMatches' => 2, 'numOfMatches' => 1, 'InfoList' => [$event]]])
            ->push('', 401, ['WWW-Authenticate' => 'Digest realm="test", nonce="nonce", qop="auth", algorithm=MD5'])
            ->push(['AcsEvent' => ['totalMatches' => 2, 'numOfMatches' => 1, 'InfoList' => [array_merge($event, ['serialNo' => 2])]]])]);
        (new PollDevice($device->id))->handle(app(IsapiClient::class), app(RecordMovement::class));
        $this->assertDatabaseCount('movements', 2);
        $this->assertNotNull($device->fresh()->last_polled_at);
        Http::assertSent(fn ($request) => isset($request['AcsEventCond']) && $request['AcsEventCond']['searchResultPosition'] === 1);
    }

    public function test_incomplete_poll_does_not_advance_cursor(): void
    {
        $device = $this->device();
        Http::fake(fn ($request) => $request->hasHeader('Authorization')
            ? Http::response(['AcsEvent' => ['totalMatches' => 1, 'numOfMatches' => 0, 'InfoList' => []]])
            : Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="test", nonce="nonce", qop="auth", algorithm=MD5']));
        try {
            (new PollDevice($device->id))->handle(app(IsapiClient::class), app(RecordMovement::class));
            $this->fail('La consulta incompleta debió fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('incompleta', $exception->getMessage());
        }
        $this->assertNull($device->fresh()->last_polled_at);
        $this->assertNotNull($device->fresh()->last_error);
    }

    public function test_backup_requires_encryption_password_and_audits_failure(): void
    {
        config(['backup.backup.password' => null]);
        try {
            (new RunBackup)->handle();
            $this->fail('El respaldo sin clave debió fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $exception->getMessage());
        }
        $this->assertSame(1, Activity::where('description', 'backup.failed')->count());
    }

    private function device(): Device
    {
        return Device::create(['name' => 'Puerta', 'model' => 'Test', 'base_url' => 'https://192.0.2.20', 'username' => 'admin', 'password' => 'secret', 'location' => 'Norte', 'read_enabled' => true]);
    }
}
