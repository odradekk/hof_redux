<?php

declare(strict_types=1);

namespace App\Domain\Dungeon;

use App\Domain\Character\Attributes;
use App\Domain\Combat\RandomSource;

/** Random room outcomes. Callers supply the random stream and persist the result. */
final class RoomRules
{
    /** @return array{money: int, items: array<string, int>, bonus: bool} */
    public static function chest(array $room, RandomSource $random, int $highestLuk = 0): array
    {
        [$low, $high] = $room['money'] ?? [0, 0];
        $items = [];
        $bonus = false;
        if (! empty($room['loot'])) {
            $rolls = $room['rolls'] ?? 1;
            $bonus = self::chance(Attributes::chestBonusChance($highestLuk), $random);
            for ($roll = 0; $roll < $rolls + ($bonus ? 1 : 0); $roll++) {
                $id = self::weighted($room['loot'], $random);
                $items[$id] = ($items[$id] ?? 0) + 1;
            }
        }

        return ['money' => $random->integer($low, $high), 'items' => $items, 'bonus' => $bonus];
    }

    /** True with the given percentage; 0% never rolls, so it does not advance the stream. */
    public static function chance(int $percent, RandomSource $random): bool
    {
        return $percent > 0 && $random->integer(1, 100) <= $percent;
    }

    public static function disarm(int $highestDex, RandomSource $random): bool
    {
        return self::chance(Attributes::disarmChance($highestDex), $random);
    }

    /** Fixed damage to one member, or null when the member dodges. */
    public static function trapDamage(array $room, int $dex, RandomSource $random): ?int
    {
        if (self::chance(Attributes::dodgeChance($dex), $random)) {
            return null;
        }
        [$low, $high] = $room['damage'];

        return $random->integer($low, $high);
    }

    public static function scout(int $highestLuk, RandomSource $random): bool
    {
        return self::chance(Attributes::scoutChance($highestLuk), $random);
    }

    /** Outcomes marked lucky weigh more with the party's highest LUK. */
    public static function eventOutcome(array $room, int $choice, RandomSource $random, int $highestLuk = 0): array
    {
        $outcomes = $room['choices'][$choice]['outcomes'] ?? throw new \InvalidArgumentException('Unknown event choice.');
        $weights = array_map(static fn (array $outcome): int => ($outcome['lucky'] ?? false) ? Attributes::luckyWeight($outcome['weight'], $highestLuk) : $outcome['weight'], $outcomes);

        return $outcomes[(int) self::weighted($weights, $random)];
    }

    /** Inclusive 1..sum(weights) roll, matching the hunt encounter convention. */
    public static function weighted(array $weights, RandomSource $random): string
    {
        $roll = $random->integer(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }
        throw new \LogicException('Weighted selection failed.');
    }

    /** Amount from a percentage of a maximum; any non-zero percentage gives at least 1. */
    public static function percentOf(int $maximum, int $percent): int
    {
        return $percent > 0 ? max(1, intdiv($maximum * $percent, 100)) : 0;
    }
}
