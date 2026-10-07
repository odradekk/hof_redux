<?php

declare(strict_types=1);

namespace App\Domain\Dungeon;

use InvalidArgumentException;

/**
 * A validated, hand-authored dungeon: rooms joined by undirected edges. Construction
 * rejects any definition that could strand a party or reference malformed rules.
 */
final class DungeonMap
{
    public const TYPES = ['empty', 'battle', 'chest', 'trap', 'rest', 'event', 'exit'];

    /** Damage is fixed points so that maximum HP matters outside battle; recovery is a percentage. */
    public const EFFECTS = ['heal_percent', 'damage', 'sp_percent', 'stamina', 'money', 'item'];

    public const MAX_DAMAGE = 100000;

    /** @var array<string, list<string>> */
    private array $neighbors = [];

    public function __construct(public readonly string $id, private readonly array $definition)
    {
        $rooms = $definition['rooms'] ?? null;
        self::check(is_array($rooms) && $rooms !== [], 'needs rooms');
        $positions = [];
        foreach ($rooms as $roomId => $room) {
            self::check(is_string($roomId) && preg_match('/^[a-z0-9_]{1,32}$/', $roomId) === 1, "invalid room ID {$roomId}");
            self::check(in_array($room['type'] ?? null, self::TYPES, true), "{$roomId} has an unknown type");
            self::check(is_string($room['name'] ?? null) && $room['name'] !== '', "{$roomId} needs a name");
            $pos = $room['pos'] ?? null;
            self::check(is_array($pos) && count($pos) === 2 && is_int($pos[0]) && is_int($pos[1]) && min($pos) >= 0 && max($pos) <= 20, "{$roomId} needs a grid position");
            self::check(! isset($positions[$pos[0].':'.$pos[1]]), "{$roomId} shares a grid position");
            $positions[$pos[0].':'.$pos[1]] = true;
            self::checkRoom($roomId, $room);
            $this->neighbors[$roomId] = [];
        }
        foreach ($definition['edges'] ?? [] as $edge) {
            self::check(is_array($edge) && count($edge) === 2 && is_string($edge[0]) && is_string($edge[1]), 'edges join two room IDs');
            [$a, $b] = $edge;
            self::check(isset($rooms[$a], $rooms[$b]), "edge {$a}-{$b} references a missing room");
            self::check($a !== $b, "edge {$a}-{$b} is a loop");
            self::check(! in_array($b, $this->neighbors[$a], true), "edge {$a}-{$b} is duplicated");
            $this->neighbors[$a][] = $b;
            $this->neighbors[$b][] = $a;
        }
        $entrance = $definition['entrance'] ?? null;
        self::check(is_string($entrance) && isset($rooms[$entrance]), 'entrance must be a room');
        self::check($rooms[$entrance]['type'] === 'empty', 'entrance must be an empty room');
        $reached = $this->reachable($entrance);
        self::check(count($reached) === count($rooms), 'every room must be reachable from the entrance');
        self::check(array_filter($rooms, static fn (array $room): bool => $room['type'] === 'exit') !== [], 'needs an exit');
        $reward = $definition['clear_reward'] ?? [];
        self::check(is_int($reward['money'] ?? 0) && ($reward['money'] ?? 0) >= 0, 'clear reward money must be non-negative');
        self::checkQuantities($reward['items'] ?? [], 'clear reward items');
    }

    public function name(): string
    {
        return $this->definition['name'];
    }

    public function definition(): array
    {
        return $this->definition;
    }

    public function entrance(): string
    {
        return $this->definition['entrance'];
    }

    public function rooms(): array
    {
        return $this->definition['rooms'];
    }

    public function room(string $id): array
    {
        return $this->definition['rooms'][$id] ?? throw new InvalidArgumentException("Unknown room {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->definition['rooms'][$id]);
    }

    /** @return list<string> */
    public function neighbors(string $id): array
    {
        $this->room($id);

        return $this->neighbors[$id];
    }

    public function adjacent(string $from, string $to): bool
    {
        return $this->has($from) && in_array($to, $this->neighbors[$from], true);
    }

    /** @return list<array{0: string, 1: string}> */
    public function edges(): array
    {
        return $this->definition['edges'];
    }

    /**
     * Fog of war: visited rooms and the rooms next to them are on the map; only visited
     * rooms reveal what they contain.
     *
     * @param  list<string>  $visited
     * @return list<string>
     */
    public function visible(array $visited): array
    {
        $visible = [];
        foreach ($visited as $id) {
            $visible[$id] = true;
            foreach ($this->neighbors($id) as $next) {
                $visible[$next] = true;
            }
        }

        return array_keys($visible);
    }

    /** Content references for validation against the catalog. */
    public function references(): array
    {
        $references = ['monsters' => [], 'items' => array_keys($this->definition['clear_reward']['items'] ?? []), 'areas' => []];
        if (($this->definition['requires_item'] ?? null) !== null) {
            $references['items'][] = $this->definition['requires_item'];
        }
        foreach ($this->rooms() as $room) {
            array_push($references['monsters'], ...array_keys($room['encounters'] ?? []), ...($room['fixed'] ?? []));
            array_push($references['items'], ...array_keys($room['loot'] ?? []));
            if (isset($room['area'])) {
                $references['areas'][] = $room['area'];
            }
            foreach ($room['choices'] ?? [] as $choice) {
                foreach ($choice['outcomes'] as $outcome) {
                    foreach ($outcome['effects'] as $effect) {
                        if (isset($effect['item'])) {
                            $references['items'][] = $effect['item'];
                        }
                    }
                }
            }
        }

        return array_map(static fn (array $ids): array => array_values(array_unique(array_map('strval', $ids))), $references);
    }

    private function reachable(string $start): array
    {
        $seen = [$start => true];
        $queue = [$start];
        while ($queue !== []) {
            foreach ($this->neighbors[array_shift($queue)] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $seen;
    }

    private static function checkRoom(string $id, array $room): void
    {
        switch ($room['type']) {
            case 'battle':
                self::check(isset($room['area']) || isset($room['encounters']) || isset($room['fixed']), "{$id} needs enemies");
                self::check(! isset($room['area']) || is_string($room['area']), "{$id} area must be an ID");
                foreach ($room['encounters'] ?? [] as $weight) {
                    self::check(is_int($weight) && $weight > 0, "{$id} encounter weights must be positive integers");
                }
                self::check(! isset($room['fixed']) || (is_array($room['fixed']) && array_is_list($room['fixed']) && count($room['fixed']) <= 5), "{$id} fixed enemies must be a list of at most five");
                $count = $room['count'] ?? 'party';
                self::check($count === 'party' || (is_int($count) && $count >= 0 && $count <= 5), "{$id} count must be 'party' or 0-5");
                self::check($count === 'party' || $count > 0 || ! empty($room['fixed']), "{$id} has no enemies");
                self::check($count === 0 || isset($room['area']) || isset($room['encounters']), "{$id} random enemies need a pool");
                break;
            case 'chest':
                self::checkRange($room['money'] ?? [0, 0], 0, 1000000, "{$id} money");
                self::checkQuantities($room['loot'] ?? [], "{$id} loot");
                self::check(is_int($room['rolls'] ?? 1) && ($room['rolls'] ?? 1) >= 1 && ($room['rolls'] ?? 1) <= 5, "{$id} rolls must be 1-5");
                self::check(empty($room['loot']) || ($room['rolls'] ?? 1) >= 1, "{$id} loot needs rolls");
                self::checkPoints($room['open_stamina'] ?? 0, "{$id} open_stamina");
                break;
            case 'trap':
                self::checkRange($room['damage'] ?? null, 0, self::MAX_DAMAGE, "{$id} damage");
                self::checkPoints($room['stamina_loss'] ?? 0, "{$id} stamina_loss");
                break;
            case 'rest':
                self::check(is_int($room['uses'] ?? null) && $room['uses'] >= 1 && $room['uses'] <= 10, "{$id} uses must be 1-10");
                self::checkPoints($room['heal_percent'] ?? 0, "{$id} heal_percent");
                self::checkPoints($room['stamina'] ?? 0, "{$id} stamina");
                break;
            case 'event':
                self::check(is_string($room['text'] ?? null) && $room['text'] !== '', "{$id} needs text");
                $choices = $room['choices'] ?? null;
                self::check(is_array($choices) && array_is_list($choices) && count($choices) >= 1 && count($choices) <= 4, "{$id} needs 1-4 choices");
                foreach ($choices as $choice) {
                    self::check(is_string($choice['label'] ?? null) && $choice['label'] !== '', "{$id} choices need labels");
                    $outcomes = $choice['outcomes'] ?? null;
                    self::check(is_array($outcomes) && array_is_list($outcomes) && $outcomes !== [], "{$id} choices need outcomes");
                    foreach ($outcomes as $outcome) {
                        self::check(is_int($outcome['weight'] ?? null) && $outcome['weight'] > 0, "{$id} outcome weights must be positive");
                        self::check(is_bool($outcome['lucky'] ?? false), "{$id} lucky must be true or false");
                        self::check(is_string($outcome['text'] ?? null), "{$id} outcomes need text");
                        self::check(is_array($outcome['effects'] ?? null) && array_is_list($outcome['effects']), "{$id} outcomes need an effect list");
                        foreach ($outcome['effects'] as $effect) {
                            self::checkEffect($id, $effect);
                        }
                    }
                }
                break;
        }
    }

    private static function checkEffect(string $id, mixed $effect): void
    {
        self::check(is_array($effect) && $effect !== [] && in_array(array_key_first($effect), self::EFFECTS, true), "{$id} has an unknown effect");
        $kind = array_key_first($effect);
        $value = $effect[$kind];
        match ($kind) {
            'heal_percent', 'sp_percent' => self::checkPoints($value, "{$id} {$kind}"),
            'damage' => self::check(is_int($value) && $value >= 0 && $value <= self::MAX_DAMAGE, "{$id} damage effect must be 0-".self::MAX_DAMAGE),
            'stamina' => self::check(is_int($value) && abs($value) <= 100, "{$id} stamina effect must be -100..100"),
            'money' => self::check(is_int($value) && $value >= 0 && $value <= 1000000, "{$id} money effect must be non-negative"),
            'item' => self::check(is_string($value) && is_int($effect['quantity'] ?? null) && $effect['quantity'] >= 1 && $effect['quantity'] <= 99, "{$id} item effect needs an ID and quantity"),
        };
    }

    private static function checkRange(mixed $range, int $min, int $max, string $what): void
    {
        self::check(is_array($range) && count($range) === 2 && is_int($range[0]) && is_int($range[1]) && $min <= $range[0] && $range[0] <= $range[1] && $range[1] <= $max, "{$what} must be [low, high] within {$min}-{$max}");
    }

    private static function checkPoints(mixed $value, string $what): void
    {
        self::check(is_int($value) && $value >= 0 && $value <= 100, "{$what} must be 0-100");
    }

    private static function checkQuantities(mixed $map, string $what): void
    {
        self::check(is_array($map), "{$what} must be an object");
        foreach ($map as $quantity) {
            self::check(is_int($quantity) && $quantity >= 1 && $quantity <= 99, "{$what} values must be 1-99");
        }
    }

    private static function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw new InvalidArgumentException('Invalid dungeon: '.$message);
        }
    }
}
