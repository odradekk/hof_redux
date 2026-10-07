<?php

namespace Tests\Feature\World;

use App\Application\Dungeon\DungeonService;
use App\Application\Multiplayer\BossService;
use App\Application\Player\PlayerService;
use App\Application\Player\Vitals;
use App\Models\BattleReport;
use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\CharacterFactory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DungeonTest extends TestCase
{
    use RefreshDatabase;

    private function player(int $members = 1): array
    {
        $user = User::factory()->create(['name' => 'Team '.Str::random(8)]);
        $characters = [];
        for ($i = 0; $i < $members; $i++) {
            $characters[] = app(CharacterFactory::class)->create($user, 1, 'Hero'.$i, 0);
        }

        return [$user, ...$characters];
    }

    /** Level 40 (below the cap, so experience still counts) with high stats: wins goblin fights. */
    private function strong(Character $character): Character
    {
        $character->level = 40;
        $character->stats = array_merge($character->stats, ['str' => 255, 'int' => 255, 'dex' => 0, 'spd' => 255, 'luk' => 255]);
        app(PlayerService::class)->refreshVitals($character);
        $character->stats = array_merge($character->stats, ['hp' => $character->stats['maxhp'], 'sp' => $character->stats['maxsp']]);
        $character->save();

        return $character->fresh();
    }

    private function item(User $user, string $id, int $quantity, string $location = 'warehouse'): InventoryItem
    {
        return InventoryItem::create(['user_id' => $user->id, 'item_id' => $id, 'quantity' => $quantity, 'location' => $location]);
    }

    private function service(): DungeonService
    {
        return app(DungeonService::class);
    }

    private function enter(User $user, array $party, array $pack = [], string $dungeon = 'goblin_trail'): array
    {
        return $this->service()->enter($user->id, (string) Str::uuid(), $dungeon, array_map(fn ($c) => $c->id, $party), $pack);
    }

    private function move(User $user, string $room): array
    {
        return $this->service()->move($user->id, (string) Str::uuid(), $room);
    }

    private function act(User $user, string $action, array $input = []): array
    {
        return $this->service()->act($user->id, (string) Str::uuid(), $action, $input);
    }

    private function rejected(callable $call, string $message = ''): void
    {
        try {
            $call();
            $this->fail('Expected a rejection. '.$message);
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function stamina(Character $character): int
    {
        return intdiv($character->fresh()->stamina_units, Vitals::STAMINA_UNIT);
    }

    public function test_pack_weight_boundary_and_partial_stack_split(): void
    {
        [$user, $hero] = $this->player();
        // A starting warrior has STR 10: carry 10 + 1 = 11. Herbs weigh 1.
        $herbs = $this->item($user, '4100', 20);
        $sword = $this->item($user, '1000', 1);
        $this->rejected(fn () => $this->enter($user, [$hero], [['id' => $herbs->id, 'quantity' => 12]]), 'overweight by one');
        $this->rejected(fn () => $this->enter($user, [$hero], [['id' => $sword->id, 'quantity' => 1]]), 'equipment in the pack');
        $this->assertSame(20, $herbs->fresh()->quantity);
        $this->assertDatabaseCount('dungeon_runs', 0);

        $this->enter($user, [$hero], [['id' => $herbs->id, 'quantity' => 11]]);
        $this->assertSame(9, $herbs->fresh()->quantity);
        $this->assertSame('warehouse', $herbs->fresh()->location);
        $this->assertSame(11, (int) InventoryItem::where('user_id', $user->id)->where('location', 'pack')->sum('quantity'));
        $this->rejected(fn () => $this->enter($user, [$hero]), 'second active run');
    }

    public function test_returned_plain_items_rejoin_their_warehouse_stack(): void
    {
        [$user, $hero] = $this->player();
        $herbs = $this->item($user, '4100', 5);
        $refined = $this->item($user, '4100', 1);
        $refined->forceFill(['refinement' => 1])->save();
        $this->enter($user, [$hero], [['id' => $herbs->id, 'quantity' => 3]]);
        $this->act($user, 'retreat');
        // The split-off pack stack merges back; a non-plain row stays separate.
        $this->assertSame(5, $herbs->fresh()->quantity);
        $this->assertSame(2, InventoryItem::where('user_id', $user->id)->where('item_id', '4100')->count());
        $this->assertSame(1, $refined->fresh()->quantity);
    }

    public function test_locked_dungeon_requires_map_in_warehouse(): void
    {
        [$user, $hero] = $this->player();
        $this->rejected(fn () => $this->enter($user, [$hero], [], 'ancient_cave'));
        $this->item($user, '8000', 1);
        $this->enter($user, [$hero], [], 'ancient_cave');
        $this->assertSame('mouth', DungeonRun::activeFor($user->id)->room);
    }

    public function test_entry_freezes_resting_recovery_until_the_run_ends(): void
    {
        $this->freezeTime();
        [$user, $hero] = $this->player();
        $max = $hero->stats['maxhp'];
        $hero->forceFill(['stats' => array_merge($hero->stats, ['hp' => 1]), 'health_updated_at' => now()->subHour(), 'stamina_units' => 0, 'stamina_updated_at' => now()->subSeconds(864)])->save();
        $this->enter($user, [$hero]);
        $hero->refresh();
        // One resting hour restores 20% before entry; 864 seconds restore 5 stamina points.
        $this->assertSame(1 + intdiv($max * 20, 100), $hero->stats['hp']);
        $this->assertSame(432000, $hero->stamina_units);
        $this->travel(5)->hours();
        $this->assertSame(['hp' => 1 + intdiv($max * 20, 100), 'sp' => $hero->stats['sp']], Vitals::health($hero, CarbonImmutable::now(), Vitals::resting($hero)));
        $this->assertSame(5, Vitals::current($hero)['stamina']);
        $this->act($user, 'retreat');
        $this->travel(1)->hours();
        // Recovery resumes from the stored value: two separately floored 20% steps.
        $this->assertSame(1 + 2 * intdiv($max * 20, 100), Vitals::current($hero->fresh())['hp']);
    }

    public function test_moves_follow_edges_cost_stamina_and_replay_once(): void
    {
        [$user, $hero] = $this->player();
        $this->strong($hero);
        $this->enter($user, [$hero]);
        $this->rejected(fn () => $this->move($user, 'fork'), 'not adjacent to the entrance');
        $this->rejected(fn () => $this->move($user, 'nowhere'));
        $this->assertSame(100, $this->stamina($hero));

        $key = (string) Str::uuid();
        $first = $this->service()->move($user->id, $key, 'grass');
        $this->assertSame($first, $this->service()->move($user->id, $key, 'grass'));
        $this->assertSame(1, BattleReport::count());
        // Move 2 plus battle 5, charged once.
        $this->assertSame(93, $this->stamina($hero));
        $run = DungeonRun::activeFor($user->id);
        $this->assertSame('grass', $run->room);
        $this->assertTrue($run->rooms['grass']['cleared']);
        // Revisiting a cleared battle room does not fight again.
        $this->move($user, 'gate');
        $this->move($user, 'grass');
        $this->assertSame(1, BattleReport::count());
        $this->assertSame(89, $this->stamina($hero));
    }

    public function test_battle_experience_is_immediate_but_money_and_drops_wait_for_exit(): void
    {
        [$user, $hero] = $this->player();
        $hero = $this->strong($hero);
        $money = $user->fresh()->money;
        $xp = $hero->xp;
        $this->enter($user, [$hero]);
        $this->move($user, 'grass');
        $run = DungeonRun::activeFor($user->id);
        $report = BattleReport::firstOrFail()->report;
        $this->assertSame(0, $report['winner']);
        $this->assertTrue($report['dungeon']);
        $this->assertGreaterThan($xp, $hero->fresh()->xp);
        $this->assertSame($money, $user->fresh()->money);
        $this->assertSame($report['settlement']['money'], $run->loot_money);
        $this->assertGreaterThan(0, $run->loot_money);
        $drops = array_sum($report['settlement']['items']);
        $this->assertSame($drops, (int) InventoryItem::where('user_id', $user->id)->where('location', 'loot')->sum('quantity'));

        $this->act($user, 'retreat');
        $this->assertSame($money + $run->loot_money, $user->fresh()->money);
        $this->assertSame(0, InventoryItem::where('user_id', $user->id)->whereIn('location', ['loot', 'pack'])->count());
        $this->assertSame('retreated', $run->fresh()->status);
        $this->assertNull(DungeonRun::activeFor($user->id));
    }

    public function test_town_is_locked_during_a_run_but_character_planning_is_not(): void
    {
        [$user, $hero] = $this->player();
        $hero->forceFill(['stat_points' => 3])->save();
        $this->enter($user, [$hero]);
        $players = app(PlayerService::class);
        $this->rejected(fn () => $players->execute($user->id, 'buy', (string) Str::uuid(), ['items' => [['id' => 4000, 'quantity' => 1]]]));
        $this->rejected(fn () => $players->execute($user->id, 'work', (string) Str::uuid(), ['character_id' => $hero->id]));
        $this->rejected(fn () => $players->execute($user->id, 'unequip-all', (string) Str::uuid(), ['character_id' => $hero->id]));
        app(BossService::class)->bootstrap();
        $this->rejected(fn () => app(BossService::class)->challenge($user->id, (string) Str::uuid(), 1, [$hero->id]));
        $players->execute($user->id, 'stats', (string) Str::uuid(), ['character_id' => $hero->id, 'stats' => ['str' => 3, 'int' => 0, 'dex' => 0, 'spd' => 0, 'luk' => 0]]);
        $this->assertSame(13, $hero->fresh()->stats['str']);
        $this->assertSame(10000, $user->fresh()->money);
    }

    public function test_trap_death_is_permanent_and_equipment_becomes_loot(): void
    {
        [$user, $tank, $fragile] = $this->player(2);
        $this->strong($tank);
        $this->strong($fragile);
        $this->enter($user, [$tank, $fragile]);
        $this->move($user, 'grass');
        $this->move($user, 'fork');
        $user->forceFill(['preferences' => ['party' => [$tank->id, $fragile->id]]])->save();
        // DEX 0 never dodges; any trap hit costs at least 1 HP.
        $fragile->forceFill(['stats' => array_merge($fragile->fresh()->stats, ['hp' => 1])])->save();
        $equipment = InventoryItem::where('character_id', $fragile->id)->pluck('id')->all();
        $this->assertNotEmpty($equipment);
        $this->move($user, 'pit');

        $this->assertNull(Character::find($fragile->id));
        $dead = Character::withoutGlobalScope('living')->findOrFail($fragile->id);
        $this->assertNotNull($dead->died_at);
        $this->assertSame(0, $dead->stats['hp']);
        $this->assertSame(['loot'], InventoryItem::whereIn('id', $equipment)->pluck('location')->unique()->values()->all());
        $this->assertSame([$tank->id], $user->fresh()->preferences['party']);
        $this->assertSame([$tank->id], $user->fresh()->characters()->pluck('id')->all());
        $this->assertSame('active', DungeonRun::where('user_id', $user->id)->value('status'));
        // The fallen member's gear returns with the survivors.
        $this->act($user, 'retreat');
        $this->assertSame(['warehouse'], InventoryItem::whereIn('id', $equipment)->pluck('location')->unique()->values()->all());
    }

    public function test_wipe_loses_pack_loot_and_held_money_and_offers_a_free_restart(): void
    {
        [$user, $hero] = $this->player();
        $this->strong($hero);
        $herbs = $this->item($user, '4100', 3);
        $this->enter($user, [$hero], [['id' => $herbs->id, 'quantity' => 2]]);
        $this->move($user, 'grass');
        $this->move($user, 'fork');
        $this->assertGreaterThan(0, DungeonRun::activeFor($user->id)->loot_money);
        $money = $user->fresh()->money;
        $hero->forceFill(['stats' => array_merge($hero->fresh()->stats, ['hp' => 1])])->save();
        $result = $this->move($user, 'pit');

        $this->assertSame('wiped', $result['status']);
        $this->assertSame($money, $user->fresh()->money);
        $this->assertSame(0, InventoryItem::where('user_id', $user->id)->whereIn('location', ['pack', 'loot', 'equipped'])->count());
        $this->assertSame(1, $herbs->fresh()->quantity);
        $this->assertFalse($user->fresh()->characters()->exists());

        $this->actingAs($user)->get('/')->assertRedirect('/setup');
        $this->get('/dungeons/runs/'.$result['run_id'])->assertOk()->assertSee('全灭');
        $this->get('/setup')->assertOk()->assertSee('重新出发')->assertDontSee('name="name"', false);
        $team = $user->name;
        $this->post('/setup', ['character_name' => 'Heir', 'base_type' => 2, 'gender' => 1])->assertRedirect('/');
        $this->assertSame($team, $user->fresh()->name);
        $this->assertSame(['Heir'], $user->fresh()->characters()->pluck('name')->all());
    }

    public function test_chest_event_rest_and_items_resolve_once_and_cap_at_maximum(): void
    {
        [$user, $hero] = $this->player();
        $hero = $this->strong($hero);
        $herbs = $this->item($user, '4100', 1);
        $bread = $this->item($user, '4000', 1);
        $this->enter($user, [$hero], [['id' => $herbs->id, 'quantity' => 1], ['id' => $bread->id, 'quantity' => 1]]);
        $this->rejected(fn () => $this->act($user, 'open'), 'no chest at the entrance');
        $this->move($user, 'grass');
        $this->move($user, 'cache');
        $before = $this->stamina($hero);
        $this->act($user, 'open');
        $this->assertSame($before - 1, $this->stamina($hero));
        $this->rejected(fn () => $this->act($user, 'open'), 'chest already open');

        // Healing never exceeds the maximum.
        $max = $hero->stats['maxhp'];
        $hero->forceFill(['stats' => array_merge($hero->fresh()->stats, ['hp' => $max - 3])])->save();
        $pack = InventoryItem::where('user_id', $user->id)->where('location', 'pack')->where('item_id', '4100')->firstOrFail();
        $this->act($user, 'use', ['item' => $pack->id, 'character' => $hero->id]);
        $this->assertSame($max, $hero->fresh()->stats['hp']);
        $this->assertNull($pack->fresh());
        $this->rejected(fn () => $this->act($user, 'use', ['item' => $pack->id, 'character' => $hero->id]), 'used item is gone');
        $food = InventoryItem::where('user_id', $user->id)->where('location', 'pack')->where('item_id', '4000')->firstOrFail();
        [$other] = array_slice($this->player(), 1);
        $this->rejected(fn () => $this->act($user, 'use', ['item' => $food->id, 'character' => $other->id]), 'another team');
        $stamina = $this->stamina($hero);
        $this->act($user, 'use', ['item' => $food->id, 'character' => $hero->id]);
        $this->assertSame(min(100, $stamina + 15), $this->stamina($hero));

        // Rest room: one use.
        $this->move($user, 'grass');
        $this->move($user, 'fork');
        $this->move($user, 'camp');
        $this->act($user, 'rest');
        $this->rejected(fn () => $this->act($user, 'rest'), 'rest uses exhausted');

        // Event: the "ignore" choice resolves with no effects; a second choice is rejected.
        $this->move($user, 'traveler');
        $this->rejected(fn () => $this->act($user, 'choose', ['choice' => 9]));
        $this->act($user, 'choose', ['choice' => 2]);
        $this->rejected(fn () => $this->act($user, 'choose', ['choice' => 0]));
    }

    public function test_leaving_requires_the_exit_and_pays_the_clear_reward(): void
    {
        [$user, $hero] = $this->player();
        $this->strong($hero);
        $this->enter($user, [$hero]);
        $this->rejected(fn () => $this->act($user, 'leave'));
        foreach (['grass', 'fork', 'camp', 'sentry', 'chief', 'exit'] as $room) {
            $this->assertSame('active', $this->move($user, $room)['status'], $room);
        }
        $run = DungeonRun::activeFor($user->id);
        $money = $user->fresh()->money;
        $result = $this->act($user, 'leave');
        $this->assertSame('cleared', $result['status']);
        // Clear reward: $800 and two herbs, on top of the held battle money.
        $this->assertSame($money + $run->loot_money + 800, $user->fresh()->money);
        $this->assertGreaterThanOrEqual(2, (int) InventoryItem::where('user_id', $user->id)->where('location', 'warehouse')->where('item_id', '4100')->sum('quantity'));
        $this->assertSame(0, (int) DB::table('dungeon_runs')->where('status', 'active')->count());
    }

    public function test_dungeon_pages_render_fog_of_war_and_reject_foreign_runs(): void
    {
        [$user, $hero] = $this->player();
        $this->actingAs($user)->get('/dungeons')->assertOk()->assertSee('哥布林小径')->assertSee('需要仓库中持有「古代洞穴」');
        $this->get('/dungeons/ancient_cave')->assertForbidden();
        $this->get('/dungeons/goblin_trail')->assertOk()->assertSee('进入地下城');
        $this->post('/dungeons/goblin_trail', ['operation_id' => (string) Str::uuid(), 'party' => [$hero->id]])->assertRedirect('/dungeon');
        $page = $this->get('/dungeon')->assertOk()->assertSee('小径入口')->assertSee('未探索的房间');
        // Unvisited rooms reveal neither name nor type.
        $page->assertDontSee('草丛')->assertDontSee('猎人的储物箱');
        $this->get('/dungeons/goblin_trail')->assertRedirect('/dungeon');
        $this->post('/dungeon/retreat', ['operation_id' => (string) Str::uuid()])->assertSessionHasErrors('confirm');
        $run = DungeonRun::activeFor($user->id);
        $this->post('/dungeon/retreat', ['operation_id' => (string) Str::uuid(), 'confirm' => '撤离'])->assertRedirect('/dungeons/runs/'.$run->id);
        [$stranger] = $this->player();
        $this->actingAs($stranger)->get('/dungeons/runs/'.$run->id)->assertNotFound();
    }
}
