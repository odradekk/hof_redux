<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** Exact effective condition IDs from data.judge.php, with documented description-backed defect repairs. */
final class Conditions
{
    public const IDS = [1000, 1001, 1100, 1101, 1105, 1106, 1110, 1111, 1121, 1125, 1126, 1200, 1201, 1205, 1206, 1210, 1211, 1221, 1225, 1226, 1300, 1301, 1310, 1311, 1320, 1321, 1330, 1331, 1340, 1341, 1350, 1351, 1360, 1361, 1370, 1371, 1380, 1381, 1400, 1401, 1405, 1406, 1410, 1450, 1451, 1455, 1456, 1500, 1501, 1505, 1506, 1510, 1511, 1550, 1551, 1555, 1556, 1560, 1561, 1600, 1610, 1611, 1612, 1613, 1615, 1616, 1617, 1618, 1700, 1701, 1710, 1711, 1712, 1715, 1716, 1717, 1750, 1751, 1752, 1755, 1756, 1757, 1800, 1801, 1805, 1820, 1821, 1825, 1840, 1841, 1845, 1850, 1851, 1855, 1900, 1901, 1902, 1920, 1940, 9000];

    public static function evaluate(int $id, int $q, int $row, Combatant $actor, array $teams, array $circles, array $skills, RandomSource $rng): bool
    {
        if (! in_array($id, self::IDS, true)) {
            throw new \InvalidArgumentException("Unknown condition $id");
        }
        $mine = $teams[$actor->team];
        $enemy = $teams[1 - $actor->team];
        $living = static fn (array $team): array => array_values(array_filter($team, static fn (Combatant $u): bool => $u->alive()));
        $count = static fn (array $team, callable $test): int => count(array_filter($team, $test));
        if ($id === 1000) {
            return true;
        }
        if ($id === 1001) {
            return false;
        }
        if ($id >= 1300 && $id <= 1381) {
            $stat = ['str', 'int', 'dex', 'spd', 'luk', 'ATK', 'MATK', 'DEF', 'MDEF'][intdiv($id - 1300, 10)];
            $value = match ($stat) {
                'ATK' => $actor->v['atk'][0] ?? 0, 'MATK' => $actor->v['atk'][1] ?? 0, 'DEF' => $actor->v['def'][0] ?? 0, 'MDEF' => $actor->v['def'][2] ?? 0, default => $actor->v[$stat]
            };

            return $id % 2 === 0 ? $value >= $q : $value <= $q;
        }
        if (($id >= 1100 && $id <= 1126) || ($id >= 1200 && $id <= 1226)) {
            $r = $id < 1200 ? 'hp' : 'sp';
            $n = $id % 100;
            $value = match ($n) {
                0,1 => $actor->percent($r), 5,6 => $actor->v[$r], 10,11 => $actor->v['max'.$r], default => null
            };
            if ($value !== null) {
                return in_array($n, [0, 5, 10], true) ? $value >= $q : $value <= $q;
            }
            $eligible = array_filter($living($mine), static fn (Combatant $u): bool => $r === 'hp' || $u->v['maxsp'] > 0);
            if (! $eligible) {
                return false;
            }
            $values = array_map(static fn (Combatant $u): float => $u->percent($r), $eligible);
            if ($n === 21) {
                return min($values) <= $q;
            }
            $average = array_sum($values) / count($values);

            return $n === 25 ? $average >= $q : $average <= $q;
        }
        if (in_array($id, [1400, 1401, 1405, 1406, 1450, 1451, 1455, 1456], true)) {
            $team = $id < 1450 ? $mine : $enemy;
            $dead = $id % 10 >= 5;
            $n = $count($team, static fn (Combatant $u): bool => $u->alive() !== $dead);

            return $id % 5 === 0 ? $n >= $q : $n <= $q;
        }
        if ($id === 1410) {
            return $count($living($mine), static fn (Combatant $u): bool => $u->v['position'] === 'front') >= $q;
        }
        if ($id >= 1500 && $id <= 1561) {
            $team = $id < 1550 ? $mine : $enemy;
            $kind = $id % 50;
            $n = $count($living($team), static function (Combatant $u) use ($skills, $kind): bool {
                if ($u->casting === null) {
                    return false;
                }
                $type = (int) ($skills[$u->casting]['type'] ?? 0);

                return $kind >= 10 || $type === (($kind >= 5) ? 1 : 0);
            });

            return $id % 5 === 0 ? $n >= $q : $n <= $q;
        }
        if ($id === 1600) {
            return $actor->v['state'] === 2;
        }
        if ($id >= 1610 && $id <= 1618) {
            $team = $living($id >= 1615 ? $enemy : $mine);
            $n = $count($team, static fn (Combatant $u): bool => $u->v['state'] === 2);
            if ($id === 1612 || $id === 1613 || $id === 1617 || $id === 1618) {
                if (! $team) {
                    return false;
                } $n = $n / count($team) * 100;
            }

            return in_array($id, [1610, 1612, 1615, 1617], true) ? $n >= $q : $n <= $q;
        }
        if ($id === 1700 || $id === 1701) {
            return $actor->v['position'] === ($id === 1700 ? 'front' : 'back');
        }
        if ($id >= 1710 && $id <= 1757) {
            $team = $living($id < 1750 ? $mine : $enemy);
            $last = $id % 10;
            $n = $count($team, static fn (Combatant $u): bool => $u->v['position'] === ($last < 5 ? 'front' : 'back'));

            return match ($last % 5) {
                0 => $n >= $q, 1 => $n <= $q, 2 => $n === $q
            };
        }
        if ($id >= 1800 && $id <= 1825) {
            $n = $count($id < 1820 ? $mine : $enemy, static fn (Combatant $u): bool => (bool) $u->v['summon']);

            return match ($id % 10) {
                0 => $n >= $q, 1 => $n <= $q, 5 => $n === $q
            };
        }
        if ($id >= 1840 && $id <= 1855) {
            $n = $circles[$id < 1850 ? $actor->team : 1 - $actor->team];

            return match ($id % 10) {
                0 => $n >= $q, 1 => $n <= $q, 5 => $n === $q
            };
        }

        return match ($id) {
            1900 => $actor->actions >= $q - 1, 1901 => $actor->actions <= $q - 1, 1902 => $actor->actions === $q - 1,
            1920 => ($actor->counts[$row] ?? 0) < $q, 1940 => $rng->integer(1, 100) <= $q,
            9000 => $count($enemy, static fn (Combatant $u): bool => $u->v['level'] >= $q) > 0,
            default => throw new \LogicException("Condition $id has no handler"),
        };
    }
}
