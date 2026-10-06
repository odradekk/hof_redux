<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Low stamina weakens a resolved character snapshot before the engine sees it. */
final class Fatigue
{
    /** Minimum whole stamina => percentage removed from STR/INT/DEX/SPD/LUK. */
    public const TIERS = [60 => 0, 30 => 10, 10 => 25, 1 => 40, 0 => 60];

    public static function penalty(int $stamina): int
    {
        foreach (self::TIERS as $minimum => $penalty) {
            if ($stamina >= $minimum) {
                return $penalty;
            }
        }

        throw new \InvalidArgumentException('Stamina cannot be negative.');
    }

    public static function apply(array $snapshot, int $stamina): array
    {
        $penalty = self::penalty($stamina);
        foreach (['str', 'int', 'dex', 'spd', 'luk'] as $stat) {
            $snapshot[$stat] = intdiv((int) $snapshot[$stat] * (100 - $penalty), 100);
        }
        $snapshot['fatigue'] = $penalty;

        return $snapshot;
    }
}
