<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Person;

class ApplyGroupStatus
{
    public function __construct(private RfidService $rfid) {}

    /**
     * Deactivates people in a "baja" group (revoking their active RFID) and
     * reactivates them only when they leave a "baja" group.
     */
    public function apply(Person $person, ?Group $previousGroup): void
    {
        $group = $person->group_id ? Group::find($person->group_id) : null;

        if ($group?->isBaja()) {
            $this->deactivate($person);
        } elseif ($previousGroup?->isBaja() && ! $person->active) {
            $person->active = true;
            $person->save();
        }
    }

    public function applyToGroup(Group $group): void
    {
        if (! $group->isBaja()) {
            return;
        }

        $group->people()->where('active', true)->each(fn (Person $person) => $this->deactivate($person));
    }

    private function deactivate(Person $person): void
    {
        foreach ($person->assignments()->whereNull('revoked_at')->pluck('id') as $assignmentId) {
            $this->rfid->revoke($assignmentId);
        }

        if ($person->active) {
            $person->active = false;
            $person->save();
        }
    }
}
