<?php

declare(strict_types=1);

namespace Tests\Unit\Character;

use App\Domain\Character\Attributes;
use App\Domain\Content\ContentCatalog;
use PHPUnit\Framework\TestCase;

final class AttributesTest extends TestCase
{
    public function test_level_one_vitals_of_each_base_character_stay_close_to_the_old_values(): void
    {
        $catalog = new ContentCatalog(dirname(__DIR__, 3).'/content');
        // [HP, SP] at level 1, with the old STR/INT-based values noted for comparison.
        $expected = [1 => [324, 51], 2 => [155, 110], 3 => [208, 86], 4 => [231, 71]];
        $old = [1 => [323, 51], 2 => [152, 108], 3 => [205, 85], 4 => [223, 71]];
        foreach ($expected as $type => [$hp, $sp]) {
            $base = $catalog->get('base_characters', $type);
            $job = $catalog->get('jobs', $base['job']);
            $stats = Attributes::starting($base, $type);
            self::assertSame(Attributes::STARTING_VIT[$type], $stats['vit']);
            self::assertSame([$hp, $sp], [Attributes::maxHp((float) $job['coe'][0], 1, $stats['vit']), Attributes::maxSp((float) $job['coe'][1], 1, $stats['int'])], "type {$type}");
            self::assertLessThanOrEqual(8, abs($hp - $old[$type][0]));
            self::assertLessThanOrEqual(2, abs($sp - $old[$type][1]));
        }
    }

    public function test_hp_and_sp_are_linear_and_keep_growing_past_255(): void
    {
        self::assertSame(300, Attributes::maxHp(3, 1, 0));
        self::assertSame(1200, Attributes::maxHp(3, 50, 100));
        self::assertSame(2500, Attributes::maxHp(5, 50, 150));
        self::assertSame(Attributes::maxHp(3, 50, 255) + 6, Attributes::maxHp(3, 50, 256));
        self::assertSame([400, 500], [Attributes::maxSp(1, 1, 300), Attributes::maxSp(1, 1, 400)]);
    }

    public function test_stamina_capacity_and_town_regeneration_follow_vitality(): void
    {
        self::assertSame([100, 108, 355], [Attributes::staminaMax(0), Attributes::staminaMax(8), Attributes::staminaMax(255)]);
        // A full refill always takes 24 / 5 hours: 5 × maximum per day.
        self::assertSame([500, 540, 1775], [Attributes::staminaPerDay(0), Attributes::staminaPerDay(8), Attributes::staminaPerDay(255)]);
    }

    public function test_derived_dungeon_values_and_their_limits(): void
    {
        self::assertSame([3, 3, 4, 8, 15], array_map(Attributes::dyingSteps(...), [0, 19, 20, 100, 240]));
        self::assertSame([0, 0, 1, 1, 5, 7, 10], array_map(Attributes::moveSpPercent(...), [0, 3, 4, 10, 100, 196, 400]));
        self::assertSame([10, 10, 11, 35], array_map(Attributes::carryCapacity(...), [0, 9, 10, 255]));
        self::assertSame([0, 1, 50, 50], array_map(Attributes::dodgeChance(...), [3, 4, 200, 1000]));
        self::assertSame([0, 1, 20, 50, 50], array_map(Attributes::disarmChance(...), [4, 5, 100, 250, 1000]));
        self::assertSame([20, 20, 21, 80, 80], array_map(Attributes::scoutChance(...), [0, 1, 2, 120, 1000]));
        self::assertSame([0, 1, 50, 50], array_map(Attributes::chestBonusChance(...), [2, 3, 150, 1000]));
        self::assertSame([1, 1, 2, 15], [Attributes::luckyWeight(1, 0), Attributes::luckyWeight(1, 99), Attributes::luckyWeight(1, 100), Attributes::luckyWeight(10, 50)]);
        self::assertSame([5, 61], [Attributes::equipmentCapacity(1, 0), Attributes::equipmentCapacity(50, 255)]);
    }

    public function test_initiative_needs_a_quarter_more_speed(): void
    {
        self::assertSame(1, Attributes::initiative(12.5, 10));
        self::assertSame(0, Attributes::initiative(12.49, 10));
        self::assertSame(-1, Attributes::initiative(10, 12.5));
        self::assertSame(0, Attributes::initiative(10, 10));
    }
}
