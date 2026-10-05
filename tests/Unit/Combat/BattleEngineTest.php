<?php

declare(strict_types=1);

namespace Tests\Unit\Combat;

use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\SeededRandom;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class BattleEngineTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_numerical_and_all_condition_oracles(): void
    {
        global $checks;
        ob_start();
        try {
            require __DIR__.'/condition_scenarios.php';
            self::assertSame(562, $checks);
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }

    private function unit(string $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'maxhp' => 1000, 'maxsp' => 100, 'str' => 100, 'int' => 100, 'spd' => 100, 'tactics' => [['condition' => 1000, 'skill' => 1000]]];
    }

    private function skills(array $extra = []): array
    {
        return [1000 => $extra + ['target' => ['enemy', 'individual', 1], 'type' => 0, 'pow' => 100]];
    }

    public function test_numeric_damage_and_type_one_timing(): void
    {
        $out = (new BattleEngine($this->skills()))->simulate(new BattleSnapshot([[$this->unit('a')], [$this->unit('b', ['spd' => 0])]], maxActions: 1), new SeededRandom(1));
        self::assertSame(900, $out->teams[1][0]['hp']);
        self::assertEqualsWithDelta(100 / 15, $out->events[1]['tick'], 0.00000001);
    }

    public function test_replay_and_snapshot_isolation_even_for_referenced_values(): void
    {
        $hp = 1000;
        $unit = $this->unit('a');
        $unit['hp'] = &$hp;
        $snapshot = new BattleSnapshot([[$unit], [$this->unit('b')]], 'simulation');
        $engine = new BattleEngine($this->skills());
        self::assertEquals($engine->simulate($snapshot, new SeededRandom(22)), $engine->simulate($snapshot, new SeededRandom(22)));
        self::assertSame(1000, $hp);
    }

    public function test_rank_survivors_exclude_summons_and_never_use_damage_tiebreak(): void
    {
        $engine = new BattleEngine($this->skills(['pow' => 0]));
        $out = $engine->simulate(new BattleSnapshot([[$this->unit('a'), $this->unit('summon', ['summon' => true, 'monster' => true])], [$this->unit('b')]], 'pvp', maxActions: 1), new SeededRandom(1));
        self::assertNull($out->winner);
        self::assertSame('survivor_limit', $out->reason);
        self::assertSame([[], []], $out->rewardCandidates);
        $out = $engine->simulate(new BattleSnapshot([[$this->unit('a'), $this->unit('a2')], [$this->unit('b')]], 'pvp', maxActions: 1), new SeededRandom(1));
        self::assertSame(0, $out->winner);
    }

    public function test_casting_cost_occurs_only_at_release(): void
    {
        $out = (new BattleEngine($this->skills(['charge' => [50, 20], 'sp' => 10])))->simulate(new BattleSnapshot([[$this->unit('a')], [$this->unit('b', ['spd' => 0])]], maxActions: 1), new SeededRandom(1));
        self::assertSame(1, $out->actions);
        self::assertSame(90, $out->teams[0][0]['sp']);
        self::assertSame(-20.0, $out->teams[0][0]['progress']);
        self::assertCount(1, array_filter($out->events, static fn (array $e): bool => $e['type'] === 'CastStarted'));
    }

    public function test_unknown_effect_is_rejected_before_simulating(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BattleEngine($this->skills(['inventedEffect' => 1]));
    }

    public function test_random_state_round_trips_without_process_global_rng(): void
    {
        $random = new SeededRandom(42);
        $random->integer(1, 200);
        $copy = SeededRandom::fromState($random->state());
        self::assertSame($random->integer(1, 10000), $copy->integer(1, 10000));
    }
}
