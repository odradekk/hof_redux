<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Character extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Dead characters are permanent records; no ordinary query may select them.
        self::addGlobalScope('living', static fn (Builder $query) => $query->whereNull('characters.died_at'));
    }

    protected function casts(): array
    {
        return ['stats' => 'array', 'skills' => 'array', 'tactics' => 'array', 'tactics_memo' => 'array', 'guard_policy' => 'array', 'level' => 'integer', 'xp' => 'integer', 'stat_points' => 'integer', 'skill_points' => 'integer', 'gender' => 'integer', 'base_type' => 'integer', 'stamina_units' => 'integer', 'stamina_updated_at' => 'immutable_datetime', 'health_updated_at' => 'immutable_datetime', 'died_at' => 'immutable_datetime'];
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
