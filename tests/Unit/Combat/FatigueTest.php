<?php

declare(strict_types=1);

namespace Tests\Unit\Combat;

use App\Domain\Combat\Fatigue;
use PHPUnit\Framework\TestCase;

final class FatigueTest extends TestCase
{
    public function test_penalty_tiers_change_exactly_at_their_minimum(): void
    {
        foreach ([100 => 0, 60 => 0, 59 => 10, 30 => 10, 29 => 25, 10 => 25, 9 => 40, 1 => 40, 0 => 60] as $stamina => $penalty) {
            self::assertSame($penalty, Fatigue::penalty($stamina), "stamina {$stamina}");
        }
    }

    public function test_penalty_rounds_each_stat_down_and_leaves_resources_alone(): void
    {
        $snapshot = ['str' => 99, 'int' => 7, 'dex' => 1, 'spd' => 40, 'luk' => 0, 'maxhp' => 500, 'hp' => 321, 'atk' => [10, 0]];
        $tired = Fatigue::apply($snapshot, 29);
        // 75% of each stat, rounded down: 74.25, 5.25, 0.75, 30, 0.
        self::assertSame(['str' => 74, 'int' => 5, 'dex' => 0, 'spd' => 30, 'luk' => 0], array_intersect_key($tired, array_flip(['str', 'int', 'dex', 'spd', 'luk'])));
        self::assertSame([500, 321, [10, 0], 25], [$tired['maxhp'], $tired['hp'], $tired['atk'], $tired['fatigue']]);
        self::assertSame(99, Fatigue::apply($snapshot, 60)['str']);
    }

    public function test_negative_stamina_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fatigue::penalty(-1);
    }
}
