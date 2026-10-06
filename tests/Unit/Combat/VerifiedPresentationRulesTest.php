<?php

declare(strict_types=1);

namespace Tests\Unit\Combat;

use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\SeededRandom;
use App\Domain\Combat\SnapshotFactory;
use App\Domain\Content\ContentCatalog;
use PHPUnit\Framework\TestCase;

final class VerifiedPresentationRulesTest extends TestCase
{
    public function test_monster_variants_are_seeded_without_changing_stats_drops_or_combat_rng(): void
    {
        $catalog = new ContentCatalog(dirname(__DIR__, 3).'/content');
        $prototype = $catalog->monster('1012', 1, true);
        $seen = [];
        for ($seed = 1; $seed <= 20; $seed++) {
            $random = new SeededRandom($seed);
            $controlRandom = new SeededRandom($seed);
            $control = $prototype;
            unset($control['image_variants']);
            $expected = SnapshotFactory::monster($control, 'bat', $controlRandom);
            $actual = SnapshotFactory::monster($prototype, 'bat', $random);
            $this->assertContains($actual['img'] ?? null, $prototype['image_variants']);
            $seen[$actual['img']] = true;
            $this->assertSame($actual, SnapshotFactory::monster($prototype, 'bat', new SeededRandom($seed)));
            $this->assertSame($controlRandom->state(), $random->state());
            unset($actual['img'], $actual['image_variants']);
            $this->assertSame($expected, $actual);
        }
        $this->assertCount(2, $seen);
        $this->assertArrayNotHasKey('img', $prototype);
    }

    public function test_summoned_variants_are_seeded_and_leave_combat_results_and_rng_unchanged(): void
    {
        $catalog = new ContentCatalog(dirname(__DIR__, 3).'/content');
        $prototype = $catalog->monster('1012', 1, true);
        unset($prototype['position']);
        $control = $prototype;
        unset($control['image_variants']);
        $skills = $catalog->all('skills');
        $owner = ['id' => 'owner', 'maxhp' => 1000, 'maxsp' => 1000, 'spd' => 255, 'dex' => 16, 'luk' => 1, 'tactics' => [['condition' => 1000, 'skill' => 5801]]];
        $target = ['id' => 'target', 'maxhp' => 1000, 'maxsp' => 0, 'spd' => 0];
        $snapshot = new BattleSnapshot([[$owner], [$target]], 'simulation', maxActions: 1);
        $engine = new BattleEngine($skills, [1012 => $prototype]);
        $actual = $engine->simulate($snapshot, new SeededRandom(42));
        $expected = (new BattleEngine($skills, [1012 => $control]))->simulate($snapshot, new SeededRandom(42));
        $this->assertEquals($actual, $engine->simulate($snapshot, new SeededRandom(42)));
        $summons = array_values(array_filter($actual->events, static fn (array $event): bool => $event['type'] === 'Summoned'));
        $this->assertNotEmpty($summons);
        foreach ($summons as $event) {
            $this->assertContains($event['unit']['img'] ?? null, $prototype['image_variants']);
            $saved = array_values(array_filter($actual->teams[0], static fn (array $unit): bool => $unit['id'] === $event['target']));
            $this->assertSame($event['unit']['img'], $saved[0]['img']);
        }
        $strip = function (array $value) use (&$strip): array {
            unset($value['img'], $value['image_variants']);
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $child = $strip($child);
                }
            }

            return $value;
        };
        $this->assertSame($strip((array) $expected), $strip((array) $actual));
    }

    public function test_fixed_and_previously_resolved_portraits_are_not_rerolled(): void
    {
        $catalog = new ContentCatalog(dirname(__DIR__, 3).'/content');
        $bat = $catalog->monster('1012', 1, true);
        $bat['img'] = $bat['image_variants'][0];
        $fixed = $catalog->monster('1017', 1, true);
        foreach ([$bat, $fixed] as $prototype) {
            foreach ([1, 42, 77] as $seed) {
                $unit = SnapshotFactory::monster($prototype, 'saved', new SeededRandom($seed));
                $this->assertSame($prototype['img'], $unit['img']);
            }
        }
    }

    public function test_barrier_retains_zero_base_damage_then_adds_piercing_damage(): void
    {
        $skills = [1000 => ['target' => ['enemy', 'individual', 1], 'type' => 0, 'pow' => 100]];
        foreach ([0, 30] as $pierce) {
            $actor = ['id' => 'attacker', 'maxhp' => 1000, 'maxsp' => 0, 'str' => 100, 'spd' => 100, 'SPECIAL' => ['Pierce' => [$pierce, 0]], 'tactics' => [['condition' => 1000, 'skill' => 1000]]];
            $target = ['id' => 'protected', 'maxhp' => 1000, 'maxsp' => 0, 'spd' => 0, 'SPECIAL' => ['Barrier' => true]];
            $outcome = (new BattleEngine($skills))->simulate(new BattleSnapshot([[$actor], [$target]], maxActions: 1), new SeededRandom(77));
            $this->assertSame(1000 - $pierce, $outcome->teams[1][0]['hp']);
            $this->assertFalse($outcome->teams[1][0]['SPECIAL']['Barrier']);
            $this->assertCount(1, array_filter($outcome->events, static fn (array $event): bool => $event['type'] === 'BarrierConsumed'));
        }
    }
}
