<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Character extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stats' => 'array', 'skills' => 'array', 'tactics' => 'array', 'tactics_memo' => 'array', 'guard_policy' => 'array', 'level' => 'integer', 'xp' => 'integer', 'stat_points' => 'integer', 'skill_points' => 'integer', 'gender' => 'integer', 'base_type' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(InventoryItem::class)->where('location', 'equipped');
    }
}
