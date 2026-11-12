<?php

namespace App\Services;

use App\Models\Group;

class ImportInterfaceHGroup
{
    public function __construct(private ApplyGroupStatus $status) {}

    public function import(string $source, int $externalId, string $name, ?int $parentExternalId): Group
    {
        $group = Group::firstOrNew(['source' => $source, 'external_id' => $externalId]);
        $group->name = $name;
        $group->parent_id = $parentExternalId && $parentExternalId !== $externalId
            ? $this->resolve($source, $parentExternalId)->id
            : null;
        $group->save();
        $this->status->applyToGroup($group);

        return $group;
    }

    /**
     * Returns the group for an interfazH id, creating a placeholder when it has not been received yet.
     */
    public function resolve(string $source, int $externalId): Group
    {
        return Group::firstOrCreate(
            ['source' => $source, 'external_id' => $externalId],
            ['name' => 'Grupo '.$externalId],
        );
    }
}
