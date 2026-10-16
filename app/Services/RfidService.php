<?php

namespace App\Services;

use App\Jobs\ProvisionRfid;
use App\Models\Device;
use App\Models\Person;
use App\Models\Provisioning;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RfidService
{
    public function assign(string $number, int $personId, ?int $vehicleId, array $deviceIds, User $actor): RfidAssignment
    {
        return DB::transaction(function () use ($number, $personId, $vehicleId, $deviceIds, $actor) {
            RfidTag::firstOrCreate(['number' => $number]);
            $tag = RfidTag::where('number', $number)->lockForUpdate()->firstOrFail();
            $person = Person::lockForUpdate()->findOrFail($personId);
            if (! $person->active) {
                throw ValidationException::withMessages(['personId' => 'La persona está inactiva.']);
            }
            if (RfidAssignment::where('active_tag_id', $tag->id)->exists()) {
                throw ValidationException::withMessages(['number' => 'El RFID ya tiene una asignación activa.']);
            }
            $history = RfidAssignment::where('rfid_tag_id', $tag->id)->pluck('id');
            if (Provisioning::whereIn('rfid_assignment_id', $history)->where('operation', 'delete')->where('status', '!=', 'applied')->exists()) {
                throw ValidationException::withMessages(['number' => 'Hay revocaciones pendientes en los dispositivos. No se puede reutilizar el RFID.']);
            }
            $devices = Device::whereIn('id', $deviceIds)->where('write_enabled', true)->get();
            if ($devices->count() !== count(array_unique($deviceIds))) {
                throw ValidationException::withMessages(['deviceIds' => 'Seleccione solamente dispositivos habilitados para escritura.']);
            }
            $assignment = RfidAssignment::create([
                'rfid_tag_id' => $tag->id, 'active_tag_id' => $tag->id,
                'person_id' => $personId, 'vehicle_id' => $vehicleId,
                'assigned_by' => $actor->id, 'assigned_at' => now(),
            ]);
            foreach ($devices as $device) {
                $provisioning = Provisioning::create([
                    'rfid_assignment_id' => $assignment->id, 'device_id' => $device->id, 'operation' => 'upsert',
                ]);
                ProvisionRfid::dispatch($provisioning->id)->afterCommit();
            }

            return $assignment;
        });
    }

    public function revoke(int $id): void
    {
        DB::transaction(function () use ($id) {
            $assignment = RfidAssignment::lockForUpdate()->findOrFail($id);
            if ($assignment->revoked_at) {
                throw ValidationException::withMessages(['number' => 'La asignación ya fue revocada.']);
            }
            $assignment->update(['revoked_at' => now(), 'active_tag_id' => null]);
            foreach (Provisioning::where('rfid_assignment_id', $id)->where('operation', 'upsert')->get() as $previous) {
                $provisioning = Provisioning::firstOrCreate([
                    'rfid_assignment_id' => $id, 'device_id' => $previous->device_id, 'operation' => 'delete',
                ]);
                ProvisionRfid::dispatch($provisioning->id)->afterCommit();
            }
        });
    }
}
