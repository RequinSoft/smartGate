<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends AuditedModel
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RfidAssignment::class);
    }
}
