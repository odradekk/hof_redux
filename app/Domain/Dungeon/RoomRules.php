<?php

declare(strict_types=1);

namespace App\Domain\Dungeon;

use App\Domain\Combat\RandomSource;

/** Random room outcomes. Callers supply the random stream and persist the result. */
final class RoomRules
{
    /** A trap misses a member with this chance (percent), capped by MAX_DODGE. */
    public const DEX_PER_DODGE_PERCENT = 4;

    public const MAX_DODGE = 50;

    /** @return array{money: int, items: array<string, int>} */
    public static function chest(array $room, RandomSource $random): array
    {
        [$low, $high] = $room['money'] ?? [0, 0];
        $items = [];
        if (! empty($room['loot'])) {
            for ($roll = 0; $roll < ($room['rolls'] ?? 1); $roll++) {
                $id = self::weighted($room['loot'], $random);
                $items[$id] = ($items[$id] ?? 0) + 1;
            }
        }

        return ['money' => $random->integer($low, $high), 'items' => $items];
    }

    public static function dodgeChance(int $dex): int
    {
        return min(self::MAX_DODGE, intdiv(max(0, $dex), self::DEX_PER_DODGE_PERCENT));
    }

    /** Percentage of maximum HP lost, or null when the member dodges. */
    public static function trapDamage(array $room, int $dex, RandomSource $random): ?int
    {
        if ($random->integer(1, 100) <= self::dodgeChance($dex)) {
            return null;
        }
        [$low, $high] = $room['damage_percent'];

        return $random->integer($low, $high);
    }

    public static function eventOutcome(array $room, int $choice, RandomSource $random): array
    {
        $outcomes = $room['choices'][$choice]['outcomes'] ?? throw new \InvalidArgumentException('Unknown event choice.');

        return $outcomes[(int) self::weighted(array_column($outcomes, 'weight'), $random)];
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

    /** Damage from a percentage of maximum HP; any non-zero percentage costs at least 1 HP. */
    public static function percentOf(int $maximum, int $percent): int
    {
        return $percent > 0 ? max(1, intdiv($maximum * $percent, 100)) : 0;
    }
}
