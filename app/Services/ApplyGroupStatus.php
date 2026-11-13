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

        if ($this->isInBajaGroup($group)) {
            $this->deactivate($person);
        } elseif ($this->isInBajaGroup($previousGroup) && ! $person->active) {
            $person->active = true;
            $person->save();
        }
    }

    public function applyToGroup(Group $group): void
    {
        if (! $this->isInBajaGroup($group)) {
            return;
        }

        $groupIds = [$group->id];
        $frontier = [$group->id];
        while ($frontier !== []) {
            $children = Group::whereIn('parent_id', $frontier)->pluck('id')->all();
            $frontier = array_values(array_diff($children, $groupIds));
            $groupIds = array_merge($groupIds, $frontier);
        }

        Person::whereIn('group_id', $groupIds)->where('active', true)
            ->each(fn (Person $person) => $this->deactivate($person));
    }

    private function isInBajaGroup(?Group $group): bool
    {
        $seen = [];
        while ($group && ! isset($seen[$group->id])) {
            if ($group->isBaja()) {
                return true;
            }
            $seen[$group->id] = true;
            $group = $group->parent;
        }

        return false;
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
