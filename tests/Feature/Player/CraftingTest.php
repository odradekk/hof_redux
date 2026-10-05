<?php

namespace Tests\Feature\Player;

use App\Application\Player\Crafting;
use App\Application\Player\ItemDetails;
use App\Application\Player\PlayerRules;
use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class CraftingTest extends PlayerTestCase
{
    public function test_every_retained_recipe_consumes_exact_materials_and_produces_valid_options(): void
    {
        $user = $this->player();
        $catalog = app(ContentCatalog::class);
        foreach ($catalog->all('recipes') as $id => $recipe) {
            foreach ($recipe['ingredients'] as $material => $quantity) {
                $this->item($user, (string) $material, (int) $quantity);
            }
            $key = (string) Str::uuid();
            $result = $this->command($user, 'craft', ['item_id' => $id], $key);
            $this->assertSame($result, $this->command($user, 'craft', ['item_id' => $id], $key));
            $crafted = $user->inventory()->findOrFail($result['inventory_id']);
            $this->assertSame((string) $id, $crafted->item_id);
            $this->assertContains(count($crafted->enchantments), [1, 2]);
            foreach ($crafted->enchantments as $enchantment) {
                $this->assertTrue($catalog->has('enchants', $enchantment));
            }
            $this->assertSame(1, $crafted->quantity);
            $this->assertNotEmpty(app(ItemDetails::class)->resolve($crafted)['name']);
            foreach ($recipe['ingredients'] as $material => $quantity) {
                $this->assertSame(0, $user->inventory()->where('item_id', $material)->sum('quantity'));
            }
        }
        $this->assertSame(10000, $user->fresh()->money);
    }

    public function test_special_material_and_failed_ingredient_validation_are_atomic(): void
    {
        $user = $this->player();
        $this->item($user, '6001', 3);
        $special = $this->item($user, '7000', 2);
        try {
            $this->command($user, 'craft', ['item_id' => 1000, 'material' => '7000']);
            $this->fail('Insufficient materials accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $special->fresh()->quantity);
        $this->item($user, '6001', 1);
        $result = $this->command($user, 'craft', ['item_id' => 1000, 'material' => '7000']);
        $this->assertSame('X00', $result['enchantments'][0]);
        $this->assertSame(1, $special->fresh()->quantity);
        $this->assertSame(0, $user->inventory()->where('item_id', '6001')->sum('quantity'));
    }

    public function test_crafting_option_rolls_have_reproducible_low_high_and_both_branches(): void
    {
        $catalog = app(ContentCatalog::class);
        $pools = $catalog->get('enchant_pools', '剑');
        foreach ([1, 4, 7] as $target) {
            $seed = $this->seedForRoll($target, $target + 2, 1, 9);
            $user = $this->player('roll'.$target);
            $this->item($user, '6001', 4);
            $result = app(GameAction::class)->execute($user->id, 'test-craft', (string) Str::uuid(), [],
                fn (User $locked, int $operation) => app(Crafting::class)->create($locked, '1000', null, $operation, new Randomizer(new Mt19937($seed))));
            if ($target === 1) {
                $this->assertContains($result['enchantments'][0], $pools['low']);
            }
            if ($target === 4) {
                $this->assertContains($result['enchantments'][0], $pools['high']);
            }
            if ($target === 7) {
                $this->assertContains($result['enchantments'][0], $pools['high']);
                $this->assertContains($result['enchantments'][1], $pools['low']);
            }
            $this->assertCount($target === 7 ? 2 : 1, $result['enchantments']);
        }
    }

    public function test_refinement_guaranteed_steps_fee_and_stack_conservation(): void
    {
        $user = $this->player();
        $item = $this->item($user, '1000', 2);
        $result = $this->command($user, 'refine', ['inventory_id' => $item->id, 'times' => 4]);
        $this->assertSame(4, $result['refinement']);
        $this->assertCount(4, $result['attempts']);
        $this->assertSame(9000, $user->fresh()->money);
        $this->assertSame(2, $user->inventory()->sum('quantity'));
        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_refinement_fails_once_and_destroys_only_one_unit(): void
    {
        $user = $this->player();
        $item = $this->item($user, '1000', 2, ['refinement' => 4]);
        $seed = $this->seedForRoll(60, 99);
        $key = (string) Str::uuid();
        $callback = fn (User $locked, int $operation) => app(Crafting::class)->refine($locked, $item->id, 6, $operation, new Randomizer(new Mt19937($seed)));
        $result = app(GameAction::class)->execute($user->id, 'test-refine', $key, [], $callback);
        $this->assertSame($result, app(GameAction::class)->execute($user->id, 'test-refine', $key, [], $callback));
        $this->assertTrue($result['destroyed']);
        $this->assertCount(1, $result['attempts']);
        $this->assertSame(9750, $user->fresh()->money);
        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_refinement_insufficient_funds_preserves_initial_or_partially_refined_item(): void
    {
        $user = $this->player();
        $item = $this->item($user, '1000');
        $user->forceFill(['money' => 249])->save();
        $result = $this->command($user, 'refine', ['inventory_id' => $item->id, 'times' => 4]);
        $this->assertSame([], $result['attempts']);
        $this->assertSame(0, $item->fresh()->refinement);
        $user->forceFill(['money' => 501])->save();
        $result = $this->command($user, 'refine', ['inventory_id' => $item->id, 'times' => 4]);
        $this->assertSame(2, $result['refinement']);
        $this->assertSame(1, $user->fresh()->money);
        $this->assertSame(1, $user->inventory()->sum('quantity'));
    }

    public function test_refinement_caps_at_ten_and_uses_exact_probabilities(): void
    {
        $this->assertSame([100, 100, 100, 100, 60, 40, 40, 20, 20, 10, 0], array_map(PlayerRules::refineChance(...), range(0, 10)));
        $user = $this->player();
        $item = $this->item($user, '1000', 1, ['refinement' => 9]);
        $seed = $this->seedForRoll(0, 9);
        $result = app(GameAction::class)->execute($user->id, 'test-cap', (string) Str::uuid(), [],
            fn (User $locked, int $operation) => app(Crafting::class)->refine($locked, $item->id, 10, $operation, new Randomizer(new Mt19937($seed))));
        $this->assertSame(10, $result['refinement']);
        $this->assertCount(1, $result['attempts']);
        $this->expectException(ValidationException::class);
        $this->command($user, 'refine', ['inventory_id' => $result['inventory_id'], 'times' => 1]);
    }

    public function test_item_resolution_orders_refinement_special_high_and_low_modifiers(): void
    {
        $details = app(ItemDetails::class);
        $base = $details->resolve(['item_id' => '1000']);
        $generated = $details->resolve(['item_id' => '1000', 'refinement' => 4, 'enchantments' => ['X00', '202', '100']]);
        $catalog = app(ContentCatalog::class);
        $factor = $catalog->get('enchants', '202')['operations'][0]['value'];
        $expected = round((ceil($base['atk'][0] * 1.16) + 5) * $factor) + 1;
        $this->assertEquals($expected, $generated['atk'][0]);
        $this->assertStringContainsString('+4', $generated['name']);
        $this->assertStringContainsString('力量', $generated['name']);
        $this->assertSame($base['sell_price'], $generated['sell_price']);
        foreach (array_keys($catalog->all('enchants')) as $id) {
            $this->assertNotEmpty($details->resolve(['item_id' => '1000', 'enchantments' => [(string) $id]])['name']);
            $this->assertNotEmpty($details->resolve(['item_id' => '5000', 'enchantments' => [(string) $id]])['name']);
        }
    }

    private function seedForRoll(int $low, int $high, int $min = 0, int $max = 99): int
    {
        for ($seed = 0; $seed < 10000; $seed++) {
            $value = (new Randomizer(new Mt19937($seed)))->getInt($min, $max);
            if ($value >= $low && $value <= $high) {
                return $seed;
            }
        }
        throw new \RuntimeException('No deterministic fixture seed found.');
    }
}
