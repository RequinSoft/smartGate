<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfidAssignment extends AuditedModel
{
    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(RfidTag::class, 'rfid_tag_id');
    }
}
