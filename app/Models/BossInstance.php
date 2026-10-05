<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BossInstance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'hp' => 'integer', 'sp' => 'integer', 'defeated_at' => 'immutable_datetime', 'respawns_at' => 'immutable_datetime'];
    }
}
