<?php

namespace App\Jobs;

use App\Models\Provisioning;
use App\Models\RfidAssignment;
use App\Services\IsapiClient;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProvisionRfid implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public int $provisioningId) {}

    public function handle(IsapiClient $client): void
    {
        try {
            DB::transaction(function () use ($client) {
                $item = Provisioning::findOrFail($this->provisioningId);
                $assignment = RfidAssignment::lockForUpdate()->findOrFail($item->rfid_assignment_id);
                $item->refresh();
                if (in_array($item->status, ['applied', 'cancelled'], true)) {
                    return;
                }
                if ($item->operation === 'upsert' && $assignment->revoked_at) {
                    $item->update(['status' => 'cancelled']);

                    return;
                }
                $client->provision($item->device, $assignment, $item->operation);
                $item->update(['status' => 'applied', 'last_error' => null]);
                Audit::record('device.rfid.applied', $item);
            });
        } catch (Throwable $exception) {
            // Do not persist remote bodies or credentials in the audit trail.
            Provisioning::findOrFail($this->provisioningId)->update([
                'status' => 'failed', 'last_error' => 'No se confirmó la operación. Consulte el registro del servidor.',
            ]);
            Audit::record('device.rfid.failed', properties: ['provisioning_id' => $this->provisioningId]);
            throw $exception;
        }
    }
}
