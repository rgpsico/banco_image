<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'description', 'terms', 'allows_commercial', 'is_active'])]
#[Hidden(['id'])]
class License extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['allows_commercial' => 'boolean', 'is_active' => 'boolean'];
    }
}
