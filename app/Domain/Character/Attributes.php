<?php

declare(strict_types=1);

namespace App\Domain\Character;

/**
 * Character attributes and every value derived from them (docs/rewrite/attribute-design.md).
 * Pure: no clock, storage or global randomness. Callers that roll pass the result in.
 */
final class Attributes
{
    public const STATS = ['str', 'int', 'dex', 'spd', 'luk', 'vit'];

    public const POINTS_PER_LEVEL = 5;

    /** Extracted base characters predate vitality; these keep level-1 HP close to the old values. */
    public const STARTING_VIT = [1 => 8, 2 => 3, 3 => 4, 4 => 5];

    public const STAMINA_BASE = 100;

    /** Town regeneration per day as a multiple of the maximum: a full refill takes 24/5 hours. */
    public const STAMINA_REFILLS_PER_DAY = 5;

    public const DYING_STEPS = 3;

    public const DYING_VIT_STEP = 20;

    public const CARRY_BASE = 10;

    public const CARRY_STR_STEP = 10;

    public const DODGE_DEX_STEP = 4;

    public const DODGE_MAX = 50;

    public const DISARM_DEX_STEP = 5;

    public const DISARM_MAX = 50;

    public const SCOUT_BASE = 20;

    public const SCOUT_LUK_STEP = 2;

    public const SCOUT_MAX = 80;

    public const CHEST_LUK_STEP = 3;

    public const CHEST_MAX = 50;

    /** A side whose average action rate reaches this multiple of the other side's acts first. */
    public const INITIATIVE_RATIO = 1.25;

    /** Starting progress of the side with initiative; a unit acts at 100. */
    public const INITIATIVE_PROGRESS = 50;

    public static function levelFactor(int $level): float
    {
        return 1 + ($level - 1) / 49;
    }

    /** Linear and uncapped: each point adds 1% of the job-and-level base. */
    public static function maxHp(float $coefficient, int $level, int $vit): int
    {
        return (int) round(100 * $coefficient * self::levelFactor($level) * (100 + $vit) / 100);
    }

    public static function maxSp(float $coefficient, int $level, int $int): int
    {
        return (int) round(100 * $coefficient * self::levelFactor($level) * (100 + $int) / 100);
    }

    /** Starting stats of a base character, including vitality. */
    public static function starting(array $base, int $baseType): array
    {
        $stats = [];
        foreach (self::STATS as $stat) {
            $stats[$stat] = $stat === 'vit' ? self::STARTING_VIT[$baseType] : (int) $base[$stat];
        }

        return $stats;
    }

    public static function staminaMax(int $vit): int
    {
        return self::STAMINA_BASE + max(0, $vit);
    }

    /** Whole points regenerated per day in town; one point is 86400 units, so also units per second. */
    public static function staminaPerDay(int $vit): int
    {
        return self::STAMINA_REFILLS_PER_DAY * self::staminaMax($vit);
    }

    public static function dyingSteps(int $vit): int
    {
        return self::DYING_STEPS + intdiv(max(0, $vit), self::DYING_VIT_STEP);
    }

    /** Percentage of maximum SP each standing member recovers per dungeon move. */
    public static function moveSpPercent(int $int): int
    {
        return intdiv((int) floor(sqrt(max(0, $int))), 2);
    }

    public static function carryCapacity(int $str): int
    {
        return self::CARRY_BASE + intdiv(max(0, $str), self::CARRY_STR_STEP);
    }

    /** Equipment weight limit, retained from the reference. */
    public static function equipmentCapacity(int $level, int $dex): int
    {
        return 5 + intdiv($level, 10) + intdiv(max(0, $dex), 5);
    }

    public static function dodgeChance(int $dex): int
    {
        return min(self::DODGE_MAX, intdiv(max(0, $dex), self::DODGE_DEX_STEP));
    }

    public static function disarmChance(int $highestDex): int
    {
        return min(self::DISARM_MAX, intdiv(max(0, $highestDex), self::DISARM_DEX_STEP));
    }

    public static function scoutChance(int $highestLuk): int
    {
        return min(self::SCOUT_MAX, self::SCOUT_BASE + intdiv(max(0, $highestLuk), self::SCOUT_LUK_STEP));
    }

    public static function chestBonusChance(int $highestLuk): int
    {
        return min(self::CHEST_MAX, intdiv(max(0, $highestLuk), self::CHEST_LUK_STEP));
    }

    public static function luckyWeight(int $weight, int $highestLuk): int
    {
        return intdiv($weight * (100 + max(0, $highestLuk)), 100);
    }

    /** 1 when the party acts first, -1 when it is ambushed, 0 otherwise. */
    public static function initiative(float $partyRate, float $enemyRate): int
    {
        return match (true) {
            $partyRate >= $enemyRate * self::INITIATIVE_RATIO => 1,
            $enemyRate >= $partyRate * self::INITIATIVE_RATIO => -1,
            default => 0,
        };
    }
}
