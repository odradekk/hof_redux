<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RankingChallenge extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['report' => 'array'];
    }
}
