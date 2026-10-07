<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Domain\Character\Attributes;
use App\Models\Character;

final class PlayerRules
{
    public const STATS = Attributes::STATS;

    public const RECRUIT_PRICES = [1 => 2000, 2 => 2000, 3 => 2500, 4 => 4000];

    public const GUARDS = ['always', 'never', 'life25', 'life50', 'life75', 'prob25', 'prob50', 'prob75'];

    public const REFINABLE = ['剑', '双手剑', '匕首', '魔杖', '杖', '弓', '鞭', '盾', '书', '甲', '衣服', '长袍'];

    public const WEAPONS = ['剑', '双手剑', '匕首', '矛', '短柄斧', '魔杖', '锤', '枪', '斧', '杖', '弓', '十字弓', '鞭'];

    public static function defaultTactic(Character $character): array
    {
        // Hunters start with Shot, not the melee Attack skill.
        return ['judge' => 1000, 'quantity' => 0, 'action' => $character->base_type === 4 ? 2300 : 1000];
    }

    public static function isTacticAction(int $skill): bool
    {
        // 9000 joins adjacent condition rows; 7000-series skills remain passive-only.
        return $skill < 7000 || $skill === 9000;
    }

    public static function maxPatterns(int $intelligence, int $level): int
    {
        // INT 200 and above keeps the highest tier; attributes have no upper limit.
        $count = match (true) {
            $intelligence < 10 => 2, $intelligence < 15 => 3,
            $intelligence < 30 => 4, $intelligence < 50 => 5,
            $intelligence < 80 => 6, $intelligence < 120 => 7,
            $intelligence < 160 => 8, $intelligence < 200 => 9,
            default => 10,
        };

        return $count + ($level >= 30 ? 1 : 0);
    }

    public static function slot(string $type): ?string
    {
        if (in_array($type, self::WEAPONS, true)) {
            return 'weapon';
        }

        return match ($type) {
            '盾', 'MainGauche', '书' => 'shield',
            '甲', '衣服', '长袍' => 'armor',
            '道具' => 'item', default => null,
        };
    }

    public static function capacity(Character $character): int
    {
        return Attributes::equipmentCapacity($character->level, (int) $character->stats['dex']);
    }

    public static function refineChance(int $refinement): int
    {
        return [100, 100, 100, 100, 60, 40, 40, 20, 20, 10][$refinement] ?? 0;
    }

    public static function experienceRequired(int $level): ?int
    {
        if ($level >= 50) {
            return null;
        }
        if ($level >= 40) {
            return [40 => 30000, 40000, 50000, 60000, 70000, 80000, 100000, 250000, 500000, 999990][$level];
        }

        return $level > 21 ? intdiv(2 * $level ** 3 + 100 * $level + 100, 100) * 20 : (($level - 1) ** 2 * 10 + 20);
    }

    public static function grantExperience(Character $character, int $amount): bool
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Experience cannot be negative.');
        }
        if ($character->level >= 50) {
            return false;
        }
        $character->xp += $amount;
        if ($character->xp < self::experienceRequired($character->level)) {
            return false;
        }
        // The retained rule grants at most one level and discards excess XP.
        $character->xp = 0;
        $character->level++;
        $character->stat_points += Attributes::POINTS_PER_LEVEL;
        $character->skill_points++;

        return true;
    }
}
