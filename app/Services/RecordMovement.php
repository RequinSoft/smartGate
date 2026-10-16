<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Movement;
use App\Models\Person;
use App\Models\RfidAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class RecordMovement
{
    public function record(Device $device, array $row): Movement
    {
        Validator::make($row, [
            'serialNo' => 'required|integer|min:0',
            'time' => 'required|date',
            'major' => 'required|integer',
            'minor' => 'required|integer',
            'cardNo' => 'nullable|string|max:32',
            'employeeNoString' => 'nullable|string|max:32',
        ])->validate();
        $time = CarbonImmutable::parse($row['time'])->utc();
        $card = $row['cardNo'] ?? null;
        $assignment = $card ? RfidAssignment::whereHas('tag', fn ($q) => $q->where('number', $card))
            ->where('assigned_at', '<=', $time)
            ->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', $time))
            ->first() : null;
        $personId = $assignment?->person_id;
        if (! $card && ! empty($row['employeeNoString'])) {
            $personId = Person::where('employee_number', $row['employeeNoString'])->value('id');
        }

        return Movement::firstOrCreate([
            'device_id' => $device->id,
            // A serial can be reused after reset; include the event timestamp.
            'external_id' => $row['serialNo'].'@'.$time->toIso8601String(),
        ], [
            'rfid_assignment_id' => $assignment?->id, 'person_id' => $personId,
            'vehicle_id' => $assignment?->vehicle_id, 'card_number' => $card,
            'employee_number' => $row['employeeNoString'] ?? null,
            'event_type' => $row['major'].':'.$row['minor'], 'direction' => $device->direction,
            'location' => $device->location, 'occurred_at' => $time, 'received_at' => now(),
            'payload' => $row,
        ]);
    }
}
