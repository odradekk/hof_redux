<?php

declare(strict_types=1);

require __DIR__.'/run.php';

use App\Domain\Combat\Combatant;
use App\Domain\Combat\Conditions;
use Tests\Unit\Combat\SequenceRandom;

$actor = new Combatant('actor', 0, unit('actor', 1000, ['hp' => 500, 'maxsp' => 200, 'sp' => 100, 'str' => 10, 'int' => 20, 'dex' => 30, 'spd' => 40, 'luk' => 50, 'atk' => [60, 70], 'def' => [80, 9, 90, 9], 'state' => 2]));
$actor->actions = 2;
$actor->counts = [1];
$actor->casting = 200;
$charge = new Combatant('charge', 0, unit('charge', 1000, ['summon' => true, 'position' => 'back']));
$charge->casting = 100;
$friend = new Combatant('friend', 0, unit('friend'));
$dead = new Combatant('dead', 0, unit('dead', 1000, ['hp' => 0, 'position' => 'back']));
$enemy = new Combatant('enemy', 1, unit('enemy', 1000, ['summon' => true, 'state' => 2, 'level' => 5]));
$enemy->casting = 200;
$enemy2 = new Combatant('enemy2', 1, unit('enemy2', 1000, ['position' => 'back']));
$enemyDead = new Combatant('enemy-dead', 1, unit('enemy-dead', 1000, ['hp' => 0, 'level' => 9]));
$teams = [[$actor, $charge, $friend, $dead], [$enemy, $enemy2, $enemyDead]];
$greater = [1100 => 50, 1105 => 500, 1110 => 1000, 1125 => 250 / 3, 1200 => 50, 1205 => 100, 1210 => 200, 1225 => 250 / 3, 1300 => 10, 1310 => 20, 1320 => 30, 1330 => 40, 1340 => 50, 1350 => 60, 1360 => 70, 1370 => 80, 1380 => 90, 1400 => 3, 1405 => 1, 1410 => 2, 1450 => 2, 1455 => 1, 1500 => 1, 1505 => 1, 1510 => 2, 1550 => 0, 1555 => 1, 1560 => 1, 1610 => 1, 1612 => 100 / 3, 1615 => 1, 1617 => 50, 1710 => 2, 1715 => 1, 1750 => 1, 1755 => 1, 1800 => 1, 1820 => 1, 1840 => 3, 1850 => 2, 1900 => 3, 9000 => 9];
$less = [1101 => 50, 1106 => 500, 1111 => 1000, 1121 => 50, 1126 => 250 / 3, 1201 => 50, 1206 => 100, 1211 => 200, 1221 => 50, 1226 => 250 / 3, 1301 => 10, 1311 => 20, 1321 => 30, 1331 => 40, 1341 => 50, 1351 => 60, 1361 => 70, 1371 => 80, 1381 => 90, 1401 => 3, 1406 => 1, 1451 => 2, 1456 => 1, 1501 => 1, 1506 => 1, 1511 => 2, 1551 => 0, 1556 => 1, 1561 => 1, 1611 => 1, 1613 => 100 / 3, 1616 => 1, 1618 => 50, 1711 => 2, 1716 => 1, 1751 => 1, 1756 => 1, 1801 => 1, 1821 => 1, 1841 => 3, 1851 => 2, 1901 => 3];
$equal = [1712 => 2, 1717 => 1, 1752 => 1, 1757 => 1, 1805 => 1, 1825 => 1, 1845 => 3, 1855 => 2, 1902 => 3];
foreach (Conditions::IDS as $id) {
    foreach ([0, 1, 3, 50, 100] as $quantity) {
        if (isset($greater[$id])) {
            $expected = $greater[$id] >= $quantity;
        } elseif (isset($less[$id])) {
            $expected = $less[$id] <= $quantity;
        } elseif (isset($equal[$id])) {
            $expected = $equal[$id] === $quantity;
        } else {
            $expected = match ($id) {
                1000,1600,1700 => true,1001,1701 => false,1920 => $quantity > 1,1940 => $quantity >= 1,default => throw new RuntimeException("Missing oracle $id")
            };
        }
        check(Conditions::evaluate($id, $quantity, 0, $actor, $teams, [3, 2], [100 => ['type' => 0], 200 => ['type' => 1]], new SequenceRandom) === $expected, "Condition $id quantity $quantity matches independent oracle");
    }
}
echo 'Conditions: '.count(Conditions::IDS)." IDs x5 boundaries, $checks total checks passed\n";
