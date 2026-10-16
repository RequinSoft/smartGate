<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\IsapiClient;
use App\Services\RecordMovement;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PollDevice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $deviceId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('poll:'.$this->deviceId))->releaseAfter(30)->expireAfter(360)];
    }

    public function handle(IsapiClient $client, RecordMovement $recorder): void
    {
        $device = Device::findOrFail($this->deviceId);
        $to = now()->utc();
        $from = ($device->last_polled_at ?? $to->copy()->subDay())->subMinutes(5);
        $position = 0;
        $searchId = (string) Str::uuid();
        try {
            do {
                $data = $client->events($device, $from->toIso8601String(), $to->toIso8601String(), $position, $searchId);
                foreach ($data['InfoList'] as $row) {
                    $recorder->record($device, $row);
                }
                $position += count($data['InfoList']);
                if ($position >= (int) $data['totalMatches']) {
                    break;
                }
                if (count($data['InfoList']) === 0 || $position >= 100000) {
                    throw new RuntimeException('La consulta ISAPI quedó incompleta; no se avanzó el cursor.');
                }
            } while (true);
            $device->update(['last_polled_at' => $to, 'last_error' => null]);
            Audit::record('device.events.completed', $device, ['processed' => $position]);
        } catch (Throwable $exception) {
            $device->update(['last_error' => 'Falló la lectura de eventos. Consulte el registro del servidor.']);
            Audit::record('device.events.failed', $device);
            throw $exception;
        }
    }
}
