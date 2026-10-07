<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/**
 * Low stamina weakens a character's battle output. The engine applies the multipliers to
 * formula damage, healing and action rate, so a listed penalty is the effect a player sees.
 */
final class Fatigue
{
    /** Minimum stamina as a percentage of the maximum => [output penalty %, speed penalty %]. */
    public const TIERS = [60 => [0, 0], 30 => [10, 5], 10 => [20, 10], 0 => [35, 20]];

    /** Penalties at exactly zero stamina. */
    public const EXHAUSTED = [50, 30];

    /** @return array{output: int, speed: int} */
    public static function penalty(int $stamina, int $maximum): array
    {
        if ($stamina < 0 || $maximum < 1) {
            throw new \InvalidArgumentException('Stamina cannot be negative.');
        }
        if ($stamina === 0) {
            return ['output' => self::EXHAUSTED[0], 'speed' => self::EXHAUSTED[1]];
        }
        foreach (self::TIERS as $minimum => [$output, $speed]) {
            if ($stamina * 100 >= $minimum * $maximum) {
                return ['output' => $output, 'speed' => $speed];
            }
        }

        throw new \LogicException('Fatigue tiers must end at zero.');
    }

    public static function apply(array $snapshot, int $stamina, int $maximum): array
    {
        $snapshot['fatigue'] = self::penalty($stamina, $maximum);

        return $snapshot;
    }
}
