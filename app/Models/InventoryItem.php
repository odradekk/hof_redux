<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryItem extends Model
{
    protected $guarded = ['id'];

    // Matches the PostgreSQL column default; SQLite keeps the pre-rename default in old schemas.
    protected $attributes = ['location' => 'warehouse'];

    protected function casts(): array
    {
        return ['enchantments' => 'array', 'quantity' => 'integer', 'refinement' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
