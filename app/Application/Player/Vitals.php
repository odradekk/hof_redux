<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Support\GameAction;
use App\Models\Character;
use Carbon\CarbonImmutable;

/**
 * Per-character HP, SP and stamina. Resting characters (not inside a dungeon) recover
 * with elapsed time; the stored values only change when a rule spends or settles them.
 */
final class Vitals
{
    public const STAMINA_MAX = 100;

    public const STAMINA_PER_DAY = 500;

    // One stamina point is 86400 units, so units regenerated per second equal points per day.
    public const STAMINA_UNIT = 86400;

    /** Resting recovery of current HP and SP, as a percentage of the maximum per hour. */
    public const HEALTH_PERCENT_PER_HOUR = 20;

    public function __construct(private GameAction $actions) {}

    public static function staminaUnits(Character $character, CarbonImmutable $now, bool $resting): int
    {
        if (! $resting) {
            return $character->stamina_units;
        }
        $seconds = max(0, $now->getTimestamp() - $character->stamina_updated_at->getTimestamp());

        return min(self::STAMINA_MAX * self::STAMINA_UNIT, $character->stamina_units + $seconds * self::STAMINA_PER_DAY);
    }

    public static function stamina(Character $character, CarbonImmutable $now, bool $resting): int
    {
        return intdiv(self::staminaUnits($character, $now, $resting), self::STAMINA_UNIT);
    }

    /** @return array{hp: int, sp: int} */
    public static function health(Character $character, CarbonImmutable $now, bool $resting): array
    {
        $seconds = $resting ? max(0, $now->getTimestamp() - $character->health_updated_at->getTimestamp()) : 0;
        $health = [];
        foreach (['hp', 'sp'] as $resource) {
            $maximum = (int) $character->stats['max'.$resource];
            $current = (int) ($character->stats[$resource] ?? $maximum);
            $health[$resource] = min($maximum, $current + intdiv($maximum * $seconds * self::HEALTH_PERCENT_PER_HOUR, 100 * 3600));
        }

        return $health;
    }

    /** Display values for one character. */
    public static function project(Character $character, CarbonImmutable $now, bool $resting): array
    {
        return self::health($character, $now, $resting) + [
            'maxhp' => (int) $character->stats['maxhp'], 'maxsp' => (int) $character->stats['maxsp'],
            'stamina' => self::stamina($character, $now, $resting), 'staminaMax' => self::STAMINA_MAX,
        ];
    }

    /** Current display values, using the real clock. */
    public static function current(Character $character): array
    {
        return self::project($character, CarbonImmutable::now(), true);
    }

    /**
     * Spend whole stamina points. Town activities require the full cost; dungeon actions pass
     * $clamp so an exhausted character still follows the party at zero stamina.
     */
    public function spendStamina(Character $character, int $points, int $operation, string $reason, bool $resting = true, bool $clamp = false): void
    {
        $this->actions->ensure($points >= 0 && $points <= self::STAMINA_MAX, 'Invalid stamina cost.');
        $now = CarbonImmutable::now();
        $available = self::staminaUnits($character, $now, $resting);
        $cost = $points * self::STAMINA_UNIT;
        $this->actions->ensure($clamp || $available >= $cost, $character->name.' 的体力不足。');
        $spent = min($available, $cost);
        $character->stamina_units = $available - $spent;
        $character->stamina_updated_at = $now;
        $character->save();
        $this->actions->ledger($character->user_id, $operation, 'stamina', -$spent, $reason, null, ['character_id' => $character->id, 'unit' => self::STAMINA_UNIT]);
    }

    /** Restore stamina up to the maximum; used only by dungeon rules, so no time passes. */
    public function restoreStamina(Character $character, int $points, int $operation, string $reason): void
    {
        $before = $character->stamina_units;
        $character->stamina_units = min(self::STAMINA_MAX * self::STAMINA_UNIT, $before + max(0, $points) * self::STAMINA_UNIT);
        $character->save();
        $this->actions->ledger($character->user_id, $operation, 'stamina', $character->stamina_units - $before, $reason, null, ['character_id' => $character->id, 'unit' => self::STAMINA_UNIT]);
    }

    /** Write resting recovery into the stored values, then stop time-based recovery. */
    public function settle(Character $character, CarbonImmutable $now): void
    {
        $character->stamina_units = self::staminaUnits($character, $now, true);
        $character->stamina_updated_at = $now;
        $character->stats = array_merge($character->stats, self::health($character, $now, true));
        $character->health_updated_at = $now;
        $character->save();
    }

    /** Resume resting recovery from the stored values. */
    public function resume(Character $character, CarbonImmutable $now): void
    {
        $character->stamina_updated_at = $now;
        $character->health_updated_at = $now;
        $character->save();
    }
}
