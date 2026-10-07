<?php

namespace Tests\Feature\Multiplayer;

use App\Application\Multiplayer\RankingService;
use App\Application\Player\Vitals;
use App\Application\Support\GameAction;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GameActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stamina_regeneration_and_repeated_command_are_exact(): void
    {
        $this->freezeTime();
        $user = User::create(['login' => 'player', 'password' => 'password']);
        $character = app(CharacterFactory::class)->create($user, 1, 'Hero', 0);
        $character->forceFill(['stamina_units' => 100000, 'stamina_updated_at' => now()->subSeconds(100)])->save();
        $key = (string) Str::uuid();
        $actions = app(GameAction::class);
        $run = fn () => $actions->execute($user->id, 'stamina', $key, [], function ($actor, $op) use ($character) {
            $locked = Character::lockForUpdate()->findOrFail($character->id);
            app(Vitals::class)->spendStamina($locked, 1, $op, 'test');

            return ['units' => $locked->stamina_units];
        });
        self::assertSame(['units' => 63600], $run());
        self::assertSame(['units' => 63600], $run());
        self::assertSame(63600, $character->fresh()->stamina_units);
    }

    public function test_rank2_place_capacities(): void
    {
        self::assertSame([1, 2, 2, 3, 3, 3, 4, 4, 4, 5], array_map(RankingService::place(...), range(1, 10)));
    }

    public function test_exception_after_asset_mutation_rolls_everything_back(): void
    {
        $user = User::create(['login' => 'player', 'password' => 'password']);
        $actions = app(GameAction::class);
        try {
            $actions->execute($user->id, 'fault', (string) Str::uuid(), [], function ($actor, $op) use ($actions) {
                $actions->money($actor, -500, $op, 'fault injection');
                $actions->addItem($actor, '1000', 1, $op, 'fault injection');
                throw new \RuntimeException('Injected failure after assets');
            });
            self::fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected failure after assets', $e->getMessage());
        }
        self::assertSame(10000, $user->fresh()->money);
        self::assertSame(0, DB::table('operations')->count());
        self::assertSame(0, DB::table('asset_entries')->count());
        self::assertSame(0, InventoryItem::count());
    }

    public function test_object_key_order_does_not_change_idempotent_request(): void
    {
        $user = User::create(['login' => 'player', 'password' => 'password']);
        $actions = app(GameAction::class);
        $key = (string) Str::uuid();
        $first = $actions->execute($user->id, 'ordered', $key, ['a' => ['x' => 1, 'y' => 2], 'b' => 3], fn () => ['ok' => true]);
        $second = $actions->execute($user->id, 'ordered', $key, ['b' => 3, 'a' => ['y' => 2, 'x' => 1]], fn () => ['ok' => false]);
        self::assertSame($first, $second);
    }
}
