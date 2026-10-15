<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Identificador público em ULID. O id numérico fica interno; as rotas usam o ULID. */
trait HasUlid
{
    public static function bootHasUlid(): void
    {
        static::creating(function ($model) {
            if (empty($model->ulid)) {
                $model->ulid = strtolower((string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
