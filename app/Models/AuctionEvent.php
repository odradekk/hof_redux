<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AuctionEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }
}
