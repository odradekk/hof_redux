<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BossChallenge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['report' => 'array', 'killed' => 'boolean'];
    }
}
