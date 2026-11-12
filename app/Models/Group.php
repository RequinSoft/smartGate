<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends AuditedModel
{
    public const BAJA_NAMES = ['BAJA', 'BAJAS'];

    public function isBaja(): bool
    {
        return in_array(mb_strtoupper(trim((string) $this->name)), self::BAJA_NAMES, true);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }
}
