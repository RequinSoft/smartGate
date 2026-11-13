<?php

namespace App\Models;

class Vehicle extends AuditedModel
{
    public const OWNERSHIP_TYPES = ['personal' => 'Personal', 'company' => 'De la empresa'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
