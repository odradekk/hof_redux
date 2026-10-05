<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BattleReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['report' => 'array', 'details' => 'array', 'public' => 'boolean', 'published' => 'boolean'];
    }
}
