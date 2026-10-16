<?php

namespace App\Models;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

abstract class AuditedModel extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::created(fn (Model $model) => Audit::record('created', $model, [
            'after' => $model->attributesToArray(),
        ]));
        static::updated(fn (Model $model) => Audit::record('updated', $model, [
            'before' => array_diff_key($model->getRawOriginal(), array_flip($model->getHidden())),
            'after' => $model->attributesToArray(),
        ]));
    }
}
