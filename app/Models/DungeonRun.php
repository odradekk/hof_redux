<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DungeonRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['party' => 'array', 'rooms' => 'array', 'members' => 'array', 'loot_money' => 'integer', 'steps' => 'integer', 'ended_at' => 'immutable_datetime'];
    }

    public static function activeFor(int $userId): ?self
    {
        return self::where('user_id', $userId)->where('status', 'active')->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DungeonRunEvent::class)->orderBy('sequence');
    }
}
