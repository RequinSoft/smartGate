<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Hidden(['password', 'username'])]
class Device extends AuditedModel
{
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'read_enabled' => 'boolean',
            'write_enabled' => 'boolean',
            'last_polled_at' => 'immutable_datetime',
            'online' => 'boolean',
        ];
    }
}
