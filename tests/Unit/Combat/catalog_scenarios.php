<?php

declare(strict_types=1);
use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\SeededRandom;

require __DIR__.'/run.php';
$directory = $argv[1] ?? dirname(__DIR__, 3).'/content';
$read = static function (string $kind) use ($directory): array {
    $rows = json_decode(file_get_contents($directory.'/'.$kind.'.json'), true, 512, JSON_THROW_ON_ERROR);

    return array_map(static fn (array $r): array => $r['data'], $rows);
};
$skills = $read('skills');
$monsters = $read('monsters');
// These fixtures resolve catalog population formulas without any legacy execution.
foreach ($monsters as $id => &$m) {
    if (($m['preview_only'] ?? false) || in_array((int) $id, [1079, 1900], true)) {
        unset($monsters[$id]);

        continue;
    }
    if (is_array($m['maxhp'])) {
        $m['maxhp'] = $m['maxhp']['base'] + $m['maxhp']['per_account'] * 2;
        $m['hp'] = $m['maxhp'];
        foreach (['moneyhold', 'exphold'] as $field) {
            $m[$field] = (int) round($m['maxhp'] / $m[$field]['divisor']);
        }
    }
    $m['monster'] = true;
    $m['position'] = $m['position'] ?? 'front';
} unset($m);
$executed = 0;
foreach ($skills as $id => $s) {
    if (! isset($s['target']) || $id === 3113) {
        continue;
    }
    foreach ([0, 2] as $state) {
        $actor = unit('actor', (int) $id, ['maxhp' => 1000000, 'hp' => 500000, 'maxsp' => 1000000, 'sp' => 1000000, 'spd' => 100000, 'state' => $state, 'weaponType' => array_key_first($s['limit'] ?? []) ?? 'Bow']);
        $friend = unit('friend', 1000, ['maxhp' => 1000000, 'hp' => 500000, 'spd' => 0, 'state' => $state, 'summon' => true, 'monster' => true]);
        $dead = unit('dead', 1000, ['hp' => 0]);
        $enemy = unit('enemy', 1000, ['maxhp' => 1000000, 'hp' => 500000, 'maxsp' => 1000000, 'sp' => 500000, 'spd' => 0, 'state' => $state]);
        $o = (new BattleEngine($skills, $monsters))->simulate(new BattleSnapshot([[$actor, $friend, $dead], [$enemy]], 'simulation', maxActions: 1, magicCircles: [5, 5]), new SeededRandom(100));
        check(count(array_filter($o->events, static fn (array $e): bool => $e['type'] === 'SkillUsed' && $e['skill'] === $id)) === 1, "Catalog skill $id executes");
        foreach ($o->teams as $team) {
            foreach ($team as $u) {
                check($u['hp'] >= 0 && $u['hp'] <= $u['maxhp'], "Catalog skill $id HP bounds");
                check($u['sp'] >= 0 && $u['sp'] <= $u['maxsp'], "Catalog skill $id SP bounds");
            }
        }
        $executed++;
    }
}
foreach ($monsters as $id => $m) {
    $m['id'] = 'monster:'.$id;
    $enemy = unit('enemy', 1000, ['maxhp' => 1000000, 'hp' => 1000000]);
    $o = (new BattleEngine($skills, $monsters))->simulate(new BattleSnapshot([[$enemy], [$m]], 'simulation', maxActions: 30), new SeededRandom((int) $id));
    check($o->actions <= 30, "Monster $id bounded");
}
echo "Catalog: $executed skill/state scenarios, ".count($monsters)." monster scenarios, $checks total checks passed\n";
