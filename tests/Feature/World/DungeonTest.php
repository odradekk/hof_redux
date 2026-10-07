<?php

namespace Tests\Feature\World;

use App\Application\Dungeon\DungeonService;
use App\Application\Multiplayer\BossService;
use App\Application\Player\PlayerService;
use App\Application\Player\Vitals;
use App\Domain\Character\Attributes;
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

    /**
     * Level 40 (below the cap, so experience still counts) with high stats: wins goblin fights.
     * DEX 0 never dodges or disarms a trap.
     */
    private function strong(Character $character): Character
    {
        $character->level = 40;
        $character->stats = array_merge($character->stats, ['str' => 255, 'int' => 255, 'dex' => 0, 'spd' => 255, 'luk' => 255, 'vit' => 255]);
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

    private function hp(Character $character, int $hp): void
    {
        $character->forceFill(['stats' => array_merge($character->fresh()->stats, ['hp' => $hp])])->save();
    }

    /** Walk to the trap with a member at 1 HP: the trap knocks that member down. */
    private function downAtTrap(User $user, array $party, Character $victim): void
    {
        $this->enter($user, $party);
        $this->move($user, 'grass');
        $this->move($user, 'fork');
        $this->hp($victim, 1);
        $this->move($user, 'pit');
    }

    private function members(User $user): array
    {
        return DungeonRun::where('user_id', $user->id)->latest('id')->firstOrFail()->members ?? [];
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
        // One resting hour restores 20% before entry; a warrior (VIT 8, 108 stamina) regains 540 units a second.
        $this->assertSame(1 + intdiv($max * 20, 100), $hero->stats['hp']);
        $this->assertSame(864 * 540, $hero->stamina_units);
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
        $start = $this->stamina($hero);

        $key = (string) Str::uuid();
        $first = $this->service()->move($user->id, $key, 'grass');
        $this->assertSame($first, $this->service()->move($user->id, $key, 'grass'));
        $this->assertSame(1, BattleReport::count());
        // Move 2 plus battle 5, charged once.
        $this->assertSame($start - 7, $this->stamina($hero));
        $run = DungeonRun::activeFor($user->id);
        $this->assertSame('grass', $run->room);
        $this->assertTrue($run->rooms['grass']['cleared']);
        // Revisiting a cleared battle room does not fight again; each move restores SP by INT.
        $this->move($user, 'gate');
        $hero->forceFill(['stats' => array_merge($hero->fresh()->stats, ['sp' => 0])])->save();
        $this->move($user, 'grass');
        $this->assertSame(1, BattleReport::count());
        $this->assertSame($start - 11, $this->stamina($hero));
        // INT 255: floor(floor(√255) ÷ 2) = 7%.
        $this->assertSame(7, Attributes::moveSpPercent(255));
        $this->assertSame(intdiv($hero->fresh()->stats['maxsp'] * 7, 100), $hero->fresh()->stats['sp']);
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
        // SPD 255 (rate ≈ 21) against goblins (rate ≈ 7) takes the initiative.
        $this->assertSame(1, $report['initiative']);
        $this->assertSame(Attributes::INITIATIVE_PROGRESS, $report['initial_teams'][0][0]['progress']);
        $this->assertArrayNotHasKey('progress', $report['initial_teams'][1][0]);
        $this->assertStringContainsString('抢得了先机', $run->events()->where('type', 'battle')->value('text'));
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

    public function test_a_downed_member_is_dying_and_dies_when_the_steps_run_out(): void
    {
        [$user, $tank, $fragile] = $this->player(2);
        $this->strong($tank);
        $fragile = $this->strong($fragile);
        // VIT 0 holds on for the minimum three moves.
        $fragile->forceFill(['stats' => array_merge($fragile->stats, ['vit' => 0])])->save();
        $user->forceFill(['preferences' => ['party' => [$tank->id, $fragile->id]]])->save();
        $equipment = InventoryItem::where('character_id', $fragile->id)->pluck('id')->all();
        $this->assertNotEmpty($equipment);
        $this->downAtTrap($user, [$tank, $fragile], $fragile);

        $this->assertNotNull(Character::find($fragile->id), 'A downed member is dying, not dead.');
        $this->assertSame(0, $fragile->fresh()->stats['hp']);
        $this->assertSame([$fragile->id => ['dying' => 3]], $this->members($user));
        $this->assertStringContainsString('陷入濒死', DungeonRun::activeFor($user->id)->events()->where('type', 'trap')->value('text'));
        // The dying are carried: no stamina, no SP recovery, one step lost per move.
        $stamina = $this->stamina($fragile);
        $fragile->forceFill(['stats' => array_merge($fragile->fresh()->stats, ['sp' => 0])])->save();
        $this->move($user, 'fork');
        $this->move($user, 'grass');
        $this->assertSame([$fragile->id => ['dying' => 1]], $this->members($user));
        $this->assertSame($stamina, $this->stamina($fragile));
        $this->assertSame(0, $fragile->fresh()->stats['sp']);
        $this->move($user, 'gate');

        $this->assertNull(Character::find($fragile->id));
        $dead = Character::withoutGlobalScope('living')->findOrFail($fragile->id);
        $this->assertNotNull($dead->died_at);
        $this->assertSame(0, $dead->stats['hp']);
        $this->assertSame([], $this->members($user));
        $this->assertSame(['loot'], InventoryItem::whereIn('id', $equipment)->pluck('location')->unique()->values()->all());
        $this->assertSame([$tank->id], $user->fresh()->preferences['party']);
        $this->assertSame([$tank->id], $user->fresh()->characters()->pluck('id')->all());
        $this->assertSame('active', DungeonRun::where('user_id', $user->id)->value('status'));
        $this->assertStringContainsString('没能撑到获救', DungeonRun::activeFor($user->id)->events()->where('type', 'move')->reorder('sequence', 'desc')->value('text'));
        // The fallen member's gear returns with the survivors.
        $this->act($user, 'retreat');
        $this->assertSame(['warehouse'], InventoryItem::whereIn('id', $equipment)->pluck('location')->unique()->values()->all());
    }

    public function test_healing_rescues_the_dying_once_and_a_wounded_member_dies_at_zero(): void
    {
        [$user, $tank, $fragile] = $this->player(2);
        $this->strong($tank);
        $fragile = $this->strong($fragile);
        $herbs = $this->item($user, '4100', 1);
        $bread = $this->item($user, '4000', 1);
        $this->enter($user, [$tank, $fragile], [['id' => $herbs->id, 'quantity' => 1], ['id' => $bread->id, 'quantity' => 1]]);
        $this->move($user, 'grass');
        $this->move($user, 'fork');
        $this->hp($fragile, 1);
        $this->move($user, 'pit');
        $this->assertSame(Attributes::dyingSteps(255), $this->members($user)[$fragile->id]['dying']);

        // Only an HP item can be used on the dying.
        $food = InventoryItem::where('user_id', $user->id)->where('location', 'pack')->where('item_id', '4000')->firstOrFail();
        $this->rejected(fn () => $this->act($user, 'use', ['item' => $food->id, 'character' => $fragile->id]), 'food on the dying');
        $herb = InventoryItem::where('user_id', $user->id)->where('location', 'pack')->where('item_id', '4100')->firstOrFail();
        $this->act($user, 'use', ['item' => $herb->id, 'character' => $fragile->id]);
        $this->assertSame(intdiv($fragile->stats['maxhp'] * 25, 100), $fragile->fresh()->stats['hp']);
        $this->assertSame([$fragile->id => ['wounded' => true]], $this->members($user));
        $this->assertStringContainsString('被救了回来', DungeonRun::activeFor($user->id)->events()->where('type', 'item')->value('text'));

        // A wounded member who falls again dies at once instead of becoming dying.
        $this->move($user, 'traveler');
        $this->hp($fragile, 0);
        $this->act($user, 'choose', ['choice' => 2]);
        $this->assertNull(Character::find($fragile->id));
        $this->assertSame('active', DungeonRun::where('user_id', $user->id)->value('status'));
        $this->assertStringContainsString('再次倒下', DungeonRun::activeFor($user->id)->events()->where('type', 'event')->value('text'));
    }

    public function test_rest_rescues_the_dying_and_retreat_carries_them_home(): void
    {
        [$user, $tank, $fragile, $carried] = $this->player(3);
        $this->strong($tank);
        $this->strong($fragile);
        $this->strong($carried);
        $this->downAtTrap($user, [$tank, $fragile, $carried], $fragile);
        $this->assertArrayHasKey('dying', $this->members($user)[$fragile->id]);
        // Rest heals first, so the rescued member then recovers SP and stamina with the others.
        $this->move($user, 'traveler');
        $this->move($user, 'camp');
        $stamina = $this->stamina($fragile);
        $this->act($user, 'rest');
        $this->assertSame(intdiv($fragile->fresh()->stats['maxhp'] * 30, 100), $fragile->fresh()->stats['hp']);
        $this->assertSame(['wounded' => true], $this->members($user)[$fragile->id]);
        $this->assertSame($stamina + 15, $this->stamina($fragile));

        // Another member left dying at retreat is carried home alive with 1 HP.
        $run = DungeonRun::activeFor($user->id);
        $run->members = $run->members + [$carried->id => ['dying' => 2]];
        $run->save();
        $this->hp($carried, 0);
        $this->act($user, 'retreat');
        $this->assertSame(1, $carried->fresh()->stats['hp']);
        $this->assertNull($carried->fresh()->died_at);
        $this->assertSame([], $run->fresh()->members);
    }

    public function test_dying_members_enter_battle_fallen_and_a_revival_rescues_them(): void
    {
        [$user, $priest, $fragile] = $this->player(2);
        $priest = $this->strong($priest);
        $this->strong($fragile);
        // The priest revives a fallen ally first (condition 1405: at least one dead ally), otherwise attacks.
        $priest->forceFill(['skills' => [...$priest->skills, 3040], 'tactics' => [['judge' => 1405, 'quantity' => 1, 'action' => 3040], ['judge' => 1000, 'quantity' => 0, 'action' => 1000]]])->save();
        $this->enter($user, [$priest, $fragile]);
        $run = DungeonRun::activeFor($user->id);
        $run->members = [$fragile->id => ['dying' => 5]];
        $run->save();
        $this->hp($fragile, 0);
        $this->move($user, 'grass');

        $report = BattleReport::firstOrFail()->report;
        $this->assertSame(0, $report['winner']);
        $fallen = collect($report['initial_teams'][0])->firstWhere('id', 'player:'.$fragile->id);
        $this->assertSame([0, 1], [$fallen['hp'], $fallen['state']]);
        // Enemies are counted for the one standing member only.
        $this->assertCount(1, $report['initial_teams'][1]);
        $this->assertGreaterThan(0, $fragile->fresh()->stats['hp']);
        $this->assertSame([$fragile->id => ['wounded' => true]], $this->members($user));
        $this->assertStringContainsString('被救了回来', DungeonRun::activeFor($user->id)->events()->where('type', 'battle')->value('text'));
    }

    public function test_disarming_spares_everyone_and_scouting_is_rolled_once(): void
    {
        [$user, $hero] = $this->player();
        $hero = $this->strong($hero);
        // DEX 250 disarms half the time; either outcome is checked below.
        $hero->forceFill(['stats' => array_merge($hero->stats, ['dex' => 250])])->save();
        $this->enter($user, [$hero]);
        $run = DungeonRun::activeFor($user->id);
        // The entrance's only neighbour was scouted once on entry; LUK 255 gives 80%.
        $this->assertArrayHasKey('scouted', $run->rooms['grass']);
        $this->move($user, 'grass');
        $scouted = DungeonRun::activeFor($user->id)->rooms;
        $this->assertArrayHasKey('scouted', $scouted['cache']);
        $this->assertArrayHasKey('scouted', $scouted['fork']);
        $this->move($user, 'gate');
        $this->move($user, 'grass');
        // Coming back does not reroll a room already judged.
        $this->assertSame($scouted['cache']['scouted'], DungeonRun::activeFor($user->id)->rooms['cache']['scouted']);
        $this->move($user, 'fork');
        $hp = $hero->fresh()->stats['hp'];
        $stamina = $this->stamina($hero);
        $this->move($user, 'pit');
        $text = DungeonRun::activeFor($user->id)->events()->where('type', 'trap')->value('text');
        if (str_contains($text, '拆除了陷阱')) {
            $this->assertSame($hp, $hero->fresh()->stats['hp']);
            $this->assertSame($stamina - DungeonService::MOVE_STAMINA, $this->stamina($hero));
        } else {
            $this->assertStringContainsString('触发了陷阱', $text);
            $this->assertSame($stamina - DungeonService::MOVE_STAMINA - 5, $this->stamina($hero));
        }
        $this->assertTrue(DungeonRun::activeFor($user->id)->rooms['pit']['cleared']);
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
        $this->assertSame(min(Vitals::staminaMax($hero->fresh()), $stamina + 15), $this->stamina($hero));

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
        // Unvisited rooms reveal neither name nor type unless scouted; fix the roll both ways.
        $run = DungeonRun::activeFor($user->id);
        $run->rooms = array_merge($run->rooms, ['grass' => ['scouted' => false]]);
        $run->save();
        $page = $this->get('/dungeon')->assertOk()->assertSee('小径入口')->assertSee('未探索的房间');
        $page->assertDontSee('草丛')->assertDontSee('猎人的储物箱');
        $run->rooms = array_merge($run->rooms, ['grass' => ['scouted' => true]]);
        $run->save();
        $this->get('/dungeon')->assertOk()->assertSee('草丛')->assertSee('战斗（侦察）')->assertDontSee('猎人的储物箱');
        $this->get('/dungeons/goblin_trail')->assertRedirect('/dungeon');
        $this->post('/dungeon/retreat', ['operation_id' => (string) Str::uuid()])->assertSessionHasErrors('confirm');
        $run = DungeonRun::activeFor($user->id);
        $this->post('/dungeon/retreat', ['operation_id' => (string) Str::uuid(), 'confirm' => '撤离'])->assertRedirect('/dungeons/runs/'.$run->id);
        [$stranger] = $this->player();
        $this->actingAs($stranger)->get('/dungeons/runs/'.$run->id)->assertNotFound();
    }
}
