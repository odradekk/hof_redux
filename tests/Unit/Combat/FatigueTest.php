<?php

declare(strict_types=1);

namespace Tests\Unit\Combat;

use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\Fatigue;
use App\Domain\Combat\SeededRandom;
use PHPUnit\Framework\TestCase;

final class FatigueTest extends TestCase
{
    public function test_tiers_are_a_share_of_the_maximum_and_change_exactly_at_their_minimum(): void
    {
        $cases = [
            [100, 100, 0, 0], [60, 100, 0, 0], [59, 100, 10, 5], [30, 100, 10, 5], [29, 100, 20, 10],
            [10, 100, 20, 10], [9, 100, 35, 20], [1, 100, 35, 20], [0, 100, 50, 30],
            // A larger maximum moves every threshold: 60% of 250 is 150.
            [150, 250, 0, 0], [149, 250, 10, 5], [25, 250, 20, 10], [24, 250, 35, 20],
            // Any stamina above zero is the last non-exhausted tier, even below 1%.
            [1, 250, 35, 20], [0, 250, 50, 30],
        ];
        foreach ($cases as [$stamina, $maximum, $output, $speed]) {
            self::assertSame(['output' => $output, 'speed' => $speed], Fatigue::penalty($stamina, $maximum), "{$stamina}/{$maximum}");
        }
    }

    public function test_apply_records_the_penalty_and_leaves_attributes_alone(): void
    {
        $snapshot = ['str' => 99, 'int' => 7, 'dex' => 1, 'spd' => 40, 'luk' => 0, 'maxhp' => 500, 'hp' => 321, 'atk' => [10, 0]];
        $tired = Fatigue::apply($snapshot, 29, 100);
        self::assertSame(['output' => 20, 'speed' => 10], $tired['fatigue']);
        self::assertSame($snapshot, array_diff_key($tired, ['fatigue' => true]));
    }

    public function test_negative_stamina_or_empty_maximum_is_rejected(): void
    {
        foreach ([[-1, 100], [0, 0]] as [$stamina, $maximum]) {
            try {
                Fatigue::penalty($stamina, $maximum);
                self::fail('Accepted invalid stamina.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function unit(string $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'maxhp' => 1000, 'maxsp' => 100, 'str' => 100, 'int' => 100, 'spd' => 100, 'tactics' => [['condition' => 1000, 'skill' => 1000]]];
    }

    public function test_output_penalty_scales_formula_damage_and_healing_exactly(): void
    {
        $attack = new BattleEngine([1000 => ['target' => ['enemy', 'individual', 1], 'type' => 0, 'pow' => 100]]);
        $snapshot = fn (array $fatigue): BattleSnapshot => new BattleSnapshot([[$this->unit('a', ['fatigue' => $fatigue])], [$this->unit('b', ['spd' => 0])]], maxActions: 1);
        // √100 × 10 = 100 damage when rested; the listed penalty removes exactly its share.
        self::assertSame(900, $attack->simulate($snapshot(['output' => 0, 'speed' => 0]), new SeededRandom(1))->teams[1][0]['hp']);
        self::assertSame(950, $attack->simulate($snapshot(['output' => 50, 'speed' => 0]), new SeededRandom(1))->teams[1][0]['hp']);

        $heal = new BattleEngine([1000 => ['target' => ['friend', 'individual', 1], 'type' => 1, 'pow' => 100, 'support' => 1]]);
        $out = $heal->simulate(new BattleSnapshot([[$this->unit('a', ['hp' => 500, 'fatigue' => ['output' => 35, 'speed' => 0]])], [$this->unit('b', ['spd' => 0])]], maxActions: 1), new SeededRandom(1));
        self::assertSame(565, $out->teams[0][0]['hp']);
    }

    public function test_speed_penalty_scales_the_action_rate(): void
    {
        $engine = new BattleEngine([1000 => ['target' => ['enemy', 'individual', 1], 'type' => 0, 'pow' => 0]]);
        $out = $engine->simulate(new BattleSnapshot([[$this->unit('a', ['fatigue' => ['output' => 0, 'speed' => 30]])], [$this->unit('b', ['spd' => 0])]], maxActions: 1), new SeededRandom(1));
        // Rate (√100 + 5) × 70% = 10.5 per tick.
        self::assertEqualsWithDelta(100 / 10.5, $out->events[1]['tick'], 0.00000001);
    }

    public function test_starting_progress_shortens_the_first_wait(): void
    {
        $engine = new BattleEngine([1000 => ['target' => ['enemy', 'individual', 1], 'type' => 0, 'pow' => 0]]);
        $out = $engine->simulate(new BattleSnapshot([[$this->unit('a', ['progress' => 50])], [$this->unit('b', ['spd' => 0])]], maxActions: 1), new SeededRandom(1));
        self::assertEqualsWithDelta(50 / 15, $out->events[1]['tick'], 0.00000001);
        foreach ([['progress' => 100], ['progress' => -1], ['fatigue' => ['output' => 100, 'speed' => 0]], ['fatigue' => 10]] as $invalid) {
            try {
                $engine->simulate(new BattleSnapshot([[$this->unit('a', $invalid)], [$this->unit('b')]]), new SeededRandom(1));
                self::fail('Accepted '.json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
