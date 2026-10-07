<?php

namespace Tests\Feature\Player;

use App\Application\Player\PlayerService;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class PlayerTestCase extends TestCase
{
    use RefreshDatabase;

    protected function player(string $login = 'player'): User
    {
        return User::create(['login' => $login, 'name' => $login, 'password' => 'password123']);
    }

    protected function character(User $user, int $type = 1): Character
    {
        return DB::transaction(fn () => app(CharacterFactory::class)->create(User::lockForUpdate()->findOrFail($user->id), $type, 'Hero', 0)->refresh());
    }

    protected function item(User $user, string $id, int $quantity = 1, array $attributes = []): InventoryItem
    {
        return InventoryItem::create(['user_id' => $user->id, 'item_id' => $id, 'quantity' => $quantity, 'location' => 'warehouse'] + $attributes);
    }

    protected function command(User $user, string $command, array $data = [], ?string $key = null): array
    {
        return app(PlayerService::class)->execute($user->id, $command, $key ?? (string) Str::uuid(), $data);
    }
}
