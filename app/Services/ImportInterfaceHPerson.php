<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Person;

class ImportInterfaceHPerson
{
    public function __construct(private ImportInterfaceHGroup $groups, private ApplyGroupStatus $status) {}

    /**
     * @param  int|null|false  $groupExternalId  false keeps the current group (field not sent).
     */
    public function import(string $personCode, string $name, string $source = 'interfaceh', int|null|false $groupExternalId = false): Person
    {
        $person = Person::firstOrNew(['employee_number' => $personCode]);
        $previousGroup = $person->group_id ? Group::find($person->group_id) : null;
        $person->name = $name;
        if ($groupExternalId !== false) {
            $person->group_id = $groupExternalId ? $this->groups->resolve($source, $groupExternalId)->id : null;
        }

        if (! $person->exists) {
            $person->active = true;
        }

        $person->save();
        $this->status->apply($person, $previousGroup);

        return $person;
    }
}
