<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RankingEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['party' => 'array', 'party_set_at' => 'immutable_datetime', 'challenge_at' => 'immutable_datetime'];
    }
}
