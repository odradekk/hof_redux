<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AuctionListing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['item_snapshot' => 'array', 'ends_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime', 'price' => 'integer', 'escrow' => 'integer'];
    }
}
