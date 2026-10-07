<?php

namespace Tests\Feature\Player;

use App\Application\Player\PlayerRules;
use App\Application\Player\Vitals;
use App\Domain\Character\Attributes;
use App\Domain\Content\ContentCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CharacterTest extends PlayerTestCase
{
    public function test_recruitment_prices_capacity_and_retries(): void
    {
        $user = $this->player();
        $user->forceFill(['money' => 50000])->save();
        $this->character($user);
        foreach (PlayerRules::RECRUIT_PRICES as $type => $price) {
            $before = $user->fresh()->money;
            $key = (string) Str::uuid();
            $input = ['base_type' => $type, 'gender' => 1, 'name' => 'Hero '.$type];
            $result = $this->command($user, 'recruit', $input, $key);
            $this->assertSame($result, $this->command($user, 'recruit', $input, $key));
            $this->assertSame($before - $price, $user->fresh()->money);
        }
        try {
            $this->command($user, 'recruit', ['base_type' => 1, 'gender' => 0, 'name' => 'Sixth']);
            $this->fail('Sixth recruitment accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame(5, $user->characters()->count());
        $this->assertSame(39500, $user->fresh()->money);
    }

    public function test_stats_are_nonnegative_bounded_and_owned(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $character->forceFill(['stat_points' => 5])->save();
        $this->command($user, 'stats', ['character_id' => $character->id, 'stats' => ['str' => 2, 'int' => 3]]);
        $this->assertSame(12, $character->fresh()->stats['str']);
        $this->assertSame(5, $character->fresh()->stats['int']);
        $this->assertSame(0, $character->fresh()->stat_points);
        foreach ([['str' => -1], ['str' => 256], ['str' => 1]] as $stats) {
            try {
                $this->command($user, 'stats', ['character_id' => $character->id, 'stats' => $stats]);
                $this->fail('Invalid points accepted.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(12, $character->fresh()->stats['str']);
        $other = $this->player('other');
        $this->expectException(ModelNotFoundException::class);
        $this->command($other, 'stats', ['character_id' => $character->id, 'stats' => ['str' => 1]]);
    }

    public function test_attributes_have_no_cap_and_vitality_sets_hp_and_stamina(): void
    {
        $this->freezeTime();
        $user = $this->player();
        $character = $this->character($user);
        $this->assertSame(['str' => 10, 'int' => 2, 'dex' => 4, 'spd' => 4, 'luk' => 1, 'vit' => 8], array_intersect_key($character->stats, array_flip(PlayerRules::STATS)));
        $this->assertSame([324, 51, 108], [$character->stats['maxhp'], $character->stats['maxsp'], Vitals::staminaMax($character)]);
        $character->forceFill(['stat_points' => 400, 'stamina_units' => 0, 'stamina_updated_at' => now()])->save();
        // Recovery before the change is measured at the old rate: 5 × 108 units per second.
        $this->travel(1000)->seconds();
        $this->command($user, 'stats', ['character_id' => $character->id, 'stats' => ['str' => 250, 'int' => 0, 'dex' => 0, 'spd' => 0, 'luk' => 0, 'vit' => 92]]);
        $fresh = $character->fresh();
        $this->assertSame([260, 100, 58], [$fresh->stats['str'], $fresh->stats['vit'], $fresh->stat_points]);
        $this->assertSame(Attributes::maxHp(3, 1, 100), $fresh->stats['maxhp']);
        $this->assertSame(600, $fresh->stats['maxhp']);
        $this->assertSame(324, $fresh->stats['hp'], 'A higher maximum does not heal.');
        $this->assertSame(540000, $fresh->stamina_units);
        $this->assertSame(200, Vitals::staminaMax($fresh));
        // Afterwards the new maximum regenerates 5 × 200 units per second.
        $this->travel(1000)->seconds();
        $this->assertSame(540000 + 1000000, Vitals::staminaUnits($fresh, now()->toImmutable(), true));

        // Lowering vitality clamps stamina to the new maximum.
        $fresh->forceFill(['stamina_units' => 200 * 86400, 'stamina_updated_at' => now()])->save();
        $this->item($user, '7511');
        $this->command($user, 'reset', ['character_id' => $character->id, 'item_id' => 7511]);
        $fresh = $character->fresh();
        $this->assertSame([30, 30, 58 + 230 + 70], [$fresh->stats['str'], $fresh->stats['vit'], $fresh->stat_points]);
        $this->assertSame(130 * 86400, $fresh->stamina_units);
        $this->assertSame(Attributes::maxHp(3, 1, 30), $fresh->stats['maxhp']);
    }

    public function test_skill_prerequisites_cost_and_complete_reset(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $character->forceFill(['skill_points' => 100])->save();
        $cost = (int) app(ContentCatalog::class)->get('skills', '1003')['learn'];
        $this->command($user, 'learn', ['character_id' => $character->id, 'skill_id' => 1003]);
        $this->assertContains(1003, $character->fresh()->skills);
        $this->assertSame(100 - $cost, $character->fresh()->skill_points);
        $character->refresh()->forceFill(['tactics_memo' => [['judge' => 1000, 'quantity' => 0, 'action' => 1003]]])->save();
        $this->item($user, '7520');
        $key = (string) Str::uuid();
        $this->command($user, 'reset', ['character_id' => $character->id, 'item_id' => 7520], $key);
        $this->command($user, 'reset', ['character_id' => $character->id, 'item_id' => 7520], $key);
        $this->assertSame([1000, 1001], $character->fresh()->skills);
        $this->assertSame(100, $character->fresh()->skill_points);
        $this->assertNull($character->fresh()->tactics_memo);
        $this->assertSame(0, $user->inventory()->where('item_id', '7520')->count());
        $this->expectException(ValidationException::class);
        $this->command($user, 'learn', ['character_id' => $character->id, 'skill_id' => 1119]);
    }

    public function test_job_level_prerequisite_and_equipment_return(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $before = $user->inventory()->sum('quantity');
        try {
            $this->command($user, 'job', ['character_id' => $character->id, 'job_id' => 101]);
            $this->fail('Low level change accepted.');
        } catch (ValidationException) {
        }
        $character->forceFill(['level' => 20])->save();
        $this->command($user, 'job', ['character_id' => $character->id, 'job_id' => 101]);
        $this->assertSame('101', $character->fresh()->job_id);
        $this->assertSame(0, $character->equipment()->count());
        $this->assertSame($before, $user->inventory()->where('location', 'warehouse')->sum('quantity'));
    }

    public function test_two_handed_and_shield_replacement_conserve_inventory(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $weapon = $this->item($user, '1100', 2);
        $before = $user->inventory()->sum('quantity');
        $this->command($user, 'equip', ['character_id' => $character->id, 'inventory_id' => $weapon->id]);
        $this->assertSame(0, $character->equipment()->where('slot', 'shield')->count());
        $this->assertSame('1100', $character->equipment()->where('slot', 'weapon')->first()->item_id);
        $this->assertSame(1, $weapon->fresh()->quantity);
        $this->assertSame($before, $user->inventory()->sum('quantity'));
        $shield = $user->inventory()->where('item_id', '3000')->where('location', 'warehouse')->first();
        $this->command($user, 'equip', ['character_id' => $character->id, 'inventory_id' => $shield->id]);
        $this->assertSame(0, $character->equipment()->where('slot', 'weapon')->count());
        $this->assertSame($before, $user->inventory()->sum('quantity'));
        $this->command($user, 'unequip-all', ['character_id' => $character->id]);
        $this->assertSame($before, $user->inventory()->where('location', 'warehouse')->sum('quantity'));
    }

    public function test_overweight_equipment_rolls_back_replacement(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $catalog = app(ContentCatalog::class);
        $heavy = collect($catalog->all('items'))->filter(fn ($item) => in_array($item['type'], $catalog->get('jobs', 100)['equip'], true) && ($item['handle'] ?? 0) > 5)->keys()->first();
        $this->assertNotNull($heavy);
        $item = $this->item($user, (string) $heavy);
        $before = $character->equipment()->pluck('id')->all();
        try {
            $this->command($user, 'equip', ['character_id' => $character->id, 'inventory_id' => $item->id]);
            $this->fail('Overweight item equipped.');
        } catch (ValidationException) {
        }
        $this->assertSame($before, $character->equipment()->pluck('id')->all());
        $this->assertSame('warehouse', $item->fresh()->location);
    }

    public function test_status_reset_returns_points_and_equipment(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $this->item($user, '7510');
        $before = array_sum(array_intersect_key($character->stats, array_flip(PlayerRules::STATS))) - count(PlayerRules::STATS);
        $this->command($user, 'reset', ['character_id' => $character->id, 'item_id' => 7510]);
        foreach (PlayerRules::STATS as $stat) {
            $this->assertSame(1, $character->fresh()->stats[$stat]);
        }
        $this->assertSame($before, $character->fresh()->stat_points);
        $this->assertSame(0, $character->equipment()->count());
    }

    public function test_dismissal_returns_equipment_and_protects_last_character(): void
    {
        $user = $this->player();
        $first = $this->character($user);
        $second = $this->character($user, 2);
        $this->command($user, 'party', ['characters' => [$first->id, $second->id]]);
        $before = $user->inventory()->sum('quantity');
        $this->command($user, 'dismiss', ['character_id' => $second->id]);
        $this->assertSame($before, $user->inventory()->sum('quantity'));
        $this->assertSame([$first->id], $user->fresh()->preferences['party']);
        $this->expectException(ValidationException::class);
        $this->command($user, 'dismiss', ['character_id' => $first->id]);
    }

    public function test_tactics_memo_and_rows_round_trip(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $rows = [['judge' => 1000, 'quantity' => 123, 'action' => 1001], ['judge' => 1001, 'quantity' => 9999, 'action' => 1000]];
        $this->command($user, 'tactics', ['character_id' => $character->id, 'tactics' => $rows]);
        $this->command($user, 'memo', ['character_id' => $character->id]);
        $this->command($user, 'memo', ['character_id' => $character->id]);
        $this->assertSame($rows, $character->fresh()->tactics);
        $this->command($user, 'tactics-insert', ['character_id' => $character->id, 'row' => 0]);
        $this->assertSame(1001, $character->fresh()->tactics[1]['action']);
        $this->command($user, 'tactics-delete', ['character_id' => $character->id, 'row' => 0]);
        $this->assertSame(1001, $character->fresh()->tactics[0]['action']);
        $this->assertSame(11, PlayerRules::maxPatterns(255, 50));
        $this->expectException(ValidationException::class);
        $this->command($user, 'tactics', ['character_id' => $character->id, 'tactics' => [['judge' => 9999, 'quantity' => 0, 'action' => 1000]]]);
    }

    public function test_rename_consumes_exactly_one_item(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $item = $this->item($user, '7500', 2);
        $key = (string) Str::uuid();
        $data = ['character_id' => $character->id, 'name' => '新角色'];
        $this->command($user, 'rename', $data, $key);
        $this->command($user, 'rename', $data, $key);
        $this->assertSame('新角色', $character->fresh()->name);
        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_growth_matches_thresholds_and_discards_excess_at_one_level(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $this->assertSame(20, PlayerRules::experienceRequired(1));
        $this->assertSame(999990, PlayerRules::experienceRequired(49));
        $this->assertNull(PlayerRules::experienceRequired(50));
        $this->assertFalse(PlayerRules::grantExperience($character, 19));
        $this->assertTrue(PlayerRules::grantExperience($character, 10000));
        $this->assertSame(2, $character->level);
        $this->assertSame(0, $character->xp);
        $this->assertSame(5, $character->stat_points);
        $this->assertSame(1, $character->skill_points);
        $character->level = 50;
        $this->assertFalse(PlayerRules::grantExperience($character, 999999));
    }

    public function test_hunter_empty_memo_and_repeated_row_edits_keep_learned_basic_shot(): void
    {
        $user = $this->player();
        $character = $this->character($user, 4);
        $original = $character->tactics;
        $this->command($user, 'memo', ['character_id' => $character->id]);
        $this->assertSame([['judge' => 1000, 'quantity' => 0, 'action' => 2300]], $character->fresh()->tactics);
        $this->command($user, 'memo', ['character_id' => $character->id]);
        $this->assertSame($original, $character->fresh()->tactics);
        foreach (['tactics-insert', 'tactics-insert', 'tactics-delete', 'tactics-delete'] as $command) {
            $this->command($user, $command, ['character_id' => $character->id, 'row' => 0]);
            foreach ($character->fresh()->tactics as $row) {
                $this->assertContains($row['action'], $character->skills);
            }
        }
        $this->actingAs($user)->get('/characters/'.$character->id)->assertOk();
        $this->command($user, 'tactics', ['character_id' => $character->id, 'tactics' => $character->fresh()->tactics]);
        $this->command($user, 'memo', ['character_id' => $character->id]);
        $this->command($user, 'memo', ['character_id' => $character->id]);
        foreach ($character->fresh()->tactics as $row) {
            $this->assertContains($row['action'], $character->skills);
        }
    }
}
