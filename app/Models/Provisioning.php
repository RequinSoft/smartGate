<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Provisioning extends AuditedModel
{
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(RfidAssignment::class, 'rfid_assignment_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
