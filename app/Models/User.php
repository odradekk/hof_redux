<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['login', 'name', 'password', 'stamina_updated_at', 'preferences', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean', 'money' => 'integer', 'stamina_units' => 'integer', 'stamina_updated_at' => 'immutable_datetime', 'last_login_at' => 'immutable_datetime', 'preferences' => 'array'];
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
