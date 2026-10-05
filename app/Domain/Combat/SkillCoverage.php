<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Reject new effect vocabulary until a real handler and regression exist. */
final class SkillCoverage
{
    public const SPECIAL_IDS = [1020, 1021, 1022, 1023, 1024, 1025, 1116, 1119, 1200, 1208, 1209, 1220, 2030, 2031, 2032, 2055, 2056, 2057, 2090, 2091, 2110, 2111, 3005, 3010, 3011, 3012, 3013, 3020, 3040, 5030, 5063, 3050, 3055, 3060, 5067, 3120, 3121, 3122, 3300, 3301, 3302, 3303, 3304, 3305, 3306, 3307, 3308, 3310, 3900, 3901, 4000, 5002, 5006, 5060, 5022, 5803];

    public const METADATA = ['name', 'name2', 'img', 'exp', 'learn', 'no', 'passive', 'availability', 'exclusion_reason'];

    public const FIELDS = ['sp', 'type', 'target', 'pow', 'inf', 'charge', 'invalid', 'support', 'priority', 'pierce', 'delay', 'knockback', 'poison', 'summon', 'quick', 'move', 'umove', 'limit', 'sacrifice', 'MagicCircleAdd', 'MagicCircleDeleteEnemy', 'MagicCircleDeleteTeam', 'HpRegen', 'SpRegen', 'CurePoison', 'SpRecoveryRate'];

    public static function validate(array $skills): void
    {
        foreach ($skills as $id => $s) {
            foreach ($s as $field => $value) {
                if (in_array($field, self::METADATA, true) || in_array($field, self::FIELDS, true)) {
                    continue;
                }
                if (preg_match('/^(?:P_|p_)(?:MAXHP|MAXSP|STR|INT|DEX|SPD|LUK|maxhp|maxsp|str|int|dex|spd|luk)$/D', $field)) {
                    continue;
                }
                if (preg_match('/^(?:Plus(?:STR|INT|DEX|SPD|LUK)|(?:Up|Down)(?:MAXHP|MAXSP|STR|INT|DEX|SPD|ATK|MATK|DEF|MDEF))$/D', $field)) {
                    continue;
                }
                throw new \InvalidArgumentException("Unhandled skill field $field on $id");
            }
        }
    }

    public static function report(array $skills): array
    {
        self::validate($skills);
        $report = [];
        foreach ($skills as $id => $s) {
            $report[$id] = match (true) {
                (int) $id === 3113 => 'excluded: orphan with no source effect',
                ! empty($s['passive']) => 'snapshot_passive',
                (int) $id === 9000 => 'and_condition',
                in_array((int) $id, self::SPECIAL_IDS, true) => 'named_effect',
                default => 'data_effect',
            };
        }

        return $report;
    }
}
