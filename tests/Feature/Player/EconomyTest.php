<?php

namespace Tests\Feature\Player;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EconomyTest extends PlayerTestCase
{
    public function test_work_is_exact_and_retry_safe(): void
    {
        $user = $this->player();
        $key = (string) Str::uuid();
        $first = $this->command($user, 'work', [], $key);
        $this->assertSame($first, $this->command($user, 'work', [], $key));
        $this->assertSame(10500, $user->fresh()->money);
        $this->assertSame(0, $user->fresh()->stamina_units);
        $this->assertSame(1, DB::table('operations')->count());
    }

    public function test_work_checks_exact_regeneration_boundary(): void
    {
        $this->freezeTime();
        $user = $this->player();
        $user->forceFill(['stamina_units' => 0])->save();
        $this->travel(17279)->seconds();
        try {
            $this->command($user, 'work');
            $this->fail('Work was allowed before full stamina.');
        } catch (ValidationException) {
        }
        $this->assertSame(10000, $user->fresh()->money);
        $this->travel(1)->seconds();
        $this->command($user, 'work');
        $this->assertSame(10500, $user->fresh()->money);
        $this->assertSame(0, $user->fresh()->stamina_units);
    }

    public function test_buy_and_sell_use_catalog_prices_and_quantities(): void
    {
        $user = $this->player();
        $key = (string) Str::uuid();
        $data = ['items' => [['id' => 1700, 'quantity' => 2], ['id' => 3000, 'quantity' => 3]]];
        $result = $this->command($user, 'buy', $data, $key);
        $this->command($user, 'buy', $data, $key);
        $this->assertSame(5, $user->inventory()->sum('quantity'));
        $this->assertSame(10000 - $result['total'], $user->fresh()->money);
        $item = $user->inventory()->where('item_id', '1700')->first();
        $before = $user->fresh()->money;
        $sale = $this->command($user, 'sell', ['items' => [['id' => $item->id, 'quantity' => 1]]]);
        $this->assertSame(4, $user->inventory()->sum('quantity'));
        $this->assertSame($before + $sale['total'], $user->fresh()->money);
    }

    public function test_multi_item_failure_rolls_back_every_change(): void
    {
        $user = $this->player();
        $item = $this->item($user, '1700', 2);
        try {
            $this->command($user, 'sell', ['items' => [['id' => $item->id, 'quantity' => 1], ['id' => $item->id, 'quantity' => 2]]]);
            $this->fail('Overselling should fail.');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $item->fresh()->quantity);
        $this->assertSame(10000, $user->fresh()->money);
        $this->assertSame(0, DB::table('operations')->count());
        $this->assertSame(0, DB::table('asset_entries')->count());
    }

    public function test_reusing_key_for_different_input_is_rejected(): void
    {
        $user = $this->player();
        $key = (string) Str::uuid();
        $this->command($user, 'buy', ['items' => [['id' => 1700, 'quantity' => 1]]], $key);
        $this->expectException(ValidationException::class);
        $this->command($user, 'buy', ['items' => [['id' => 1700, 'quantity' => 2]]], $key);
    }

    public function test_negative_quantity_and_unstocked_item_are_rejected(): void
    {
        $user = $this->player();
        foreach ([['id' => 1700, 'quantity' => -1], ['id' => 9000, 'quantity' => 1]] as $selection) {
            try {
                $this->command($user, 'buy', ['items' => [$selection]]);
                $this->fail('Invalid purchase accepted.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(10000, $user->fresh()->money);
        $this->assertSame(0, $user->inventory()->count());
    }

    public function test_preferences_and_owned_party_round_trip(): void
    {
        $user = $this->player();
        $character = $this->character($user);
        $this->command($user, 'party', ['characters' => [$character->id]]);
        $this->command($user, 'preferences', ['record_battle_log' => false, 'no_js_inventory' => true, 'color' => '99CC33']);
        $preferences = $user->fresh()->preferences;
        $this->assertSame([$character->id], $preferences['party']);
        $this->assertFalse($preferences['record_battle_log']);
        $this->assertTrue($preferences['no_js_inventory']);
        $this->assertSame('99cc33', $preferences['color']);
        $other = $this->character($this->player('other'));
        $this->expectException(ValidationException::class);
        $this->command($user, 'party', ['characters' => [$other->id]]);
    }

    public function test_team_rename_is_unique_and_charged_once(): void
    {
        $user = $this->player();
        $user->forceFill(['money' => 100000])->save();
        $key = (string) Str::uuid();
        $this->command($user, 'team-name', ['name' => '新队伍'], $key);
        $this->command($user, 'team-name', ['name' => '新队伍'], $key);
        $this->assertSame(0, $user->fresh()->money);
        $this->assertSame('新队伍', $user->fresh()->name);
        $other = $this->player('other');
        $other->forceFill(['money' => 100000])->save();
        try {
            $this->command($other, 'team-name', ['name' => '新队伍']);
            $this->fail('Duplicate name accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame(100000, $other->fresh()->money);
    }
}
