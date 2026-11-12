<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends AuditedModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RfidAssignment::class);
    }
}
