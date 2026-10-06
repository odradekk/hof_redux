<?php

declare(strict_types=1);

namespace Tests\Unit\Dungeon;

use App\Domain\Combat\SeededRandom;
use App\Domain\Content\ContentCatalog;
use App\Domain\Dungeon\DungeonMap;
use App\Domain\Dungeon\RoomRules;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Combat\SequenceRandom;

final class DungeonMapTest extends TestCase
{
    /** Entrance a, branch to b and c, c leads to the exit d. */
    private function definition(): array
    {
        return [
            'name' => 'Test', 'land' => 'grass', 'entrance' => 'a',
            'rooms' => [
                'a' => ['type' => 'empty', 'name' => 'A', 'pos' => [0, 0]],
                'b' => ['type' => 'chest', 'name' => 'B', 'pos' => [1, 0], 'money' => [1, 2], 'loot' => ['4000' => 1]],
                'c' => ['type' => 'battle', 'name' => 'C', 'pos' => [1, 1], 'encounters' => ['1000' => 1]],
                'd' => ['type' => 'exit', 'name' => 'D', 'pos' => [2, 1]],
            ],
            'edges' => [['a', 'b'], ['a', 'c'], ['c', 'd']],
        ];
    }

    private function rejects(array $definition, string $fragment): void
    {
        try {
            new DungeonMap('test', $definition);
            self::fail('Accepted an invalid dungeon: '.$fragment);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_adjacency_is_undirected_and_only_follows_edges(): void
    {
        $map = new DungeonMap('test', $this->definition());
        self::assertTrue($map->adjacent('a', 'c'));
        self::assertTrue($map->adjacent('c', 'a'));
        self::assertFalse($map->adjacent('a', 'd'));
        self::assertFalse($map->adjacent('b', 'c'));
        self::assertFalse($map->adjacent('a', 'missing'));
        self::assertFalse($map->adjacent('missing', 'a'));
    }

    public function test_fog_of_war_reveals_visited_rooms_and_their_neighbours_only(): void
    {
        $map = new DungeonMap('test', $this->definition());
        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $map->visible(['a']));
        self::assertEqualsCanonicalizing(['a', 'b', 'c', 'd'], $map->visible(['a', 'c']));
        self::assertEqualsCanonicalizing(['b', 'a'], $map->visible(['b']));
    }

    public function test_structure_that_could_strand_a_party_is_rejected(): void
    {
        $definition = $this->definition();
        $isolated = $definition;
        $isolated['rooms']['e'] = ['type' => 'empty', 'name' => 'E', 'pos' => [3, 3]];
        $this->rejects($isolated, 'reachable');

        $noExit = $definition;
        $noExit['rooms']['d']['type'] = 'empty';
        $this->rejects($noExit, 'needs an exit');

        $dangling = $definition;
        $dangling['edges'][] = ['d', 'z'];
        $this->rejects($dangling, 'missing room');

        $duplicate = $definition;
        $duplicate['edges'][] = ['c', 'a'];
        $this->rejects($duplicate, 'duplicated');

        $loop = $definition;
        $loop['edges'][] = ['b', 'b'];
        $this->rejects($loop, 'loop');

        $badEntrance = $definition;
        $badEntrance['entrance'] = 'c';
        $this->rejects($badEntrance, 'entrance must be an empty room');

        $overlap = $definition;
        $overlap['rooms']['d']['pos'] = [1, 1];
        $this->rejects($overlap, 'shares a grid position');
    }

    public function test_room_rules_are_checked(): void
    {
        $definition = $this->definition();
        $cases = [
            ['c', ['type' => 'battle', 'name' => 'C', 'pos' => [1, 1]], 'needs enemies'],
            ['c', ['type' => 'battle', 'name' => 'C', 'pos' => [1, 1], 'fixed' => ['1008'], 'count' => 2], 'random enemies need a pool'],
            ['c', ['type' => 'battle', 'name' => 'C', 'pos' => [1, 1], 'encounters' => ['1000' => 0]], 'positive integers'],
            ['b', ['type' => 'chest', 'name' => 'B', 'pos' => [1, 0], 'money' => [5, 1]], 'money'],
            ['b', ['type' => 'trap', 'name' => 'B', 'pos' => [1, 0], 'damage_percent' => [10, 101]], 'damage_percent'],
            ['b', ['type' => 'rest', 'name' => 'B', 'pos' => [1, 0], 'uses' => 0], 'uses'],
            ['b', ['type' => 'event', 'name' => 'B', 'pos' => [1, 0], 'text' => 't', 'choices' => [['label' => 'x', 'outcomes' => [['weight' => 1, 'text' => '', 'effects' => [['teleport' => 1]]]]]]], 'unknown effect'],
        ];
        foreach ($cases as [$id, $room, $fragment]) {
            $broken = $definition;
            $broken['rooms'][$id] = $room;
            $this->rejects($broken, $fragment);
        }
    }

    public function test_every_authored_dungeon_is_valid_and_references_existing_content(): void
    {
        $catalog = new ContentCatalog(dirname(__DIR__, 3).'/content');
        $playable = $catalog->playableMonsters();
        foreach ($catalog->all('dungeons') as $id => $definition) {
            $map = new DungeonMap((string) $id, $definition);
            $references = $map->references();
            foreach ($references['monsters'] as $monster) {
                self::assertArrayHasKey($monster, $playable, "{$id} uses monster {$monster}");
            }
            foreach ($references['items'] as $item) {
                self::assertTrue($catalog->has('items', $item), "{$id} uses item {$item}");
            }
            foreach ($references['areas'] as $area) {
                self::assertTrue($catalog->has('areas', $area), "{$id} uses area {$area}");
                foreach ($catalog->get('areas', $area)['encounters'] as $monster => [$weight]) {
                    if ($weight > 0) {
                        self::assertArrayHasKey((string) $monster, $playable, "{$id} area {$area} can roll {$monster}");
                    }
                }
            }
        }
    }

    public function test_trap_dodge_is_capped_and_damage_stays_in_range(): void
    {
        self::assertSame(0, RoomRules::dodgeChance(3));
        self::assertSame(1, RoomRules::dodgeChance(4));
        self::assertSame(50, RoomRules::dodgeChance(255));
        $room = ['damage_percent' => [10, 20]];
        // SequenceRandom yields minimum + value: a 1-100 roll of 1 dodges at DEX 4 (1%), 2 does not.
        self::assertNull(RoomRules::trapDamage($room, 4, new SequenceRandom([0])));
        self::assertSame(20, RoomRules::trapDamage($room, 4, new SequenceRandom([1, 10])));
        $random = new SeededRandom(7);
        for ($i = 0; $i < 200; $i++) {
            $damage = RoomRules::trapDamage($room, 0, $random);
            self::assertNotNull($damage);
            self::assertGreaterThanOrEqual(10, $damage);
            self::assertLessThanOrEqual(20, $damage);
        }
    }

    public function test_percent_damage_never_rounds_a_hit_to_zero(): void
    {
        self::assertSame(1, RoomRules::percentOf(9, 10));
        self::assertSame(33, RoomRules::percentOf(333, 10));
        self::assertSame(0, RoomRules::percentOf(500, 0));
    }

    public function test_weighted_choice_covers_inclusive_boundaries(): void
    {
        $weights = ['x' => 2, 'y' => 3];
        // Rolls 1..5: 1-2 pick x, 3-5 pick y.
        self::assertSame('x', RoomRules::weighted($weights, new SequenceRandom([0])));
        self::assertSame('x', RoomRules::weighted($weights, new SequenceRandom([1])));
        self::assertSame('y', RoomRules::weighted($weights, new SequenceRandom([2])));
        self::assertSame('y', RoomRules::weighted($weights, new SequenceRandom([4])));
    }
}
