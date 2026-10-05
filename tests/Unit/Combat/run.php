<?php

declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/SequenceRandom.php';
use App\Domain\Combat\BattleEngine;
use App\Domain\Combat\BattleOutcome;
use App\Domain\Combat\BattleSnapshot;
use App\Domain\Combat\Combatant;
use App\Domain\Combat\Conditions;
use App\Domain\Combat\SeededRandom;
use App\Domain\Combat\SnapshotFactory;
use Tests\Unit\Combat\SequenceRandom;

set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function unit(string $id, int $skill = 1000, array $extra = []): array
{
    return $extra + ['id' => $id, 'maxhp' => 1000, 'hp' => 1000, 'maxsp' => 1000, 'sp' => 1000, 'str' => 100, 'int' => 100, 'dex' => 25, 'spd' => 100, 'luk' => 0, 'position' => 'front', 'guard' => 'never', 'tactics' => [['condition' => 1000, 'skill' => $skill]]];
}
function skill(array $extra = []): array
{
    return $extra + ['target' => ['enemy', 'individual', 1], 'pow' => 100, 'type' => 0, 'sp' => 0];
}
function battle(array $skills, array $a, array $b, int $actions = 1, string $mode = 'simulation', array $summons = []): BattleOutcome
{
    return (new BattleEngine($skills, $summons))->simulate(new BattleSnapshot([$a, $b], $mode, maxActions: $actions), new SequenceRandom);
}
$s = [1000 => skill()];
$o = battle($s, [unit('a')], [unit('b')]);
check($o->teams[1][0]['hp'] === 900, 'Physical square-root damage');
check($o->actions === 1, 'Action bound');
check($o->events[1]['tick'] === 100 / 15, 'Type1 next distance uses sqrt(speed)+5');
$snapshot = new BattleSnapshot([[unit('a')], [unit('b')]], 'simulation', maxActions: 30);
$engine = new BattleEngine($s);
check($engine->simulate($snapshot, new SeededRandom(42)) == $engine->simulate($snapshot, new SeededRandom(42)), 'Replay deterministic');
check($snapshot->teams[0][0]['hp'] === 1000, 'Input snapshot unchanged');
$o = battle([1000 => skill(['charge' => [50, 20]])], [unit('a')], [unit('b', 1000, ['spd' => 0])]);
check(count(array_filter($o->events, fn ($e) => $e['type'] === 'CastStarted')) === 1, 'Cast starts without action');
check($o->actions === 1, 'Only completed cast consumes action');
check($o->teams[0][0]['progress'] === -20.0, 'Recovery delay after cast');
$o = battle([1000 => skill(['sp' => 1001])], [unit('a')], [unit('b')]);
check($o->teams[0][0]['sp'] === 1000 && $o->teams[1][0]['hp'] === 1000, 'Insufficient SP does not damage or consume');
$o = battle($s, [unit('a', 1000, ['state' => 2, 'hp' => 1])], [unit('b')]);
check($o->teams[0][0]['hp'] === 1, 'Poison is nonlethal');
$o = battle([1000 => skill(['priority' => 'Back'])], [unit('a')], [unit('front', 1000, ['guard' => 'always']), unit('back', 1000, ['position' => 'back'])]);
check($o->teams[1][0]['hp'] === 900 && $o->teams[1][1]['hp'] === 1000, 'Front guard redirects back target');
$o = battle([1000 => skill(['priority' => 'Back', 'invalid' => 1])], [unit('a')], [unit('front', 1000, ['guard' => 'always']), unit('back', 1000, ['position' => 'back'])]);
check($o->teams[1][1]['hp'] === 900, 'Invalid bypasses front guard');
$o = battle([1000 => skill(), 3040 => skill(['target' => ['friend', 'individual', 1], 'priority' => 'Dead', 'support' => 1])], [unit('a', 3040), unit('dead', 1000, ['hp' => 0])], [unit('b')]);
check($o->teams[0][1]['hp'] === 100 && $o->teams[0][1]['state'] === 0, 'Resurrection');
$o = battle([1000 => skill(['pow' => 0])], [unit('a'), unit('a2')], [unit('b')], 1, 'pvp');
check($o->winner === 0, 'Rank corrected survivor comparison');
$o = battle([1000 => skill(['pow' => 0])], [unit('a'), unit('summon', 1000, ['monster' => true, 'summon' => true])], [unit('b')], 1, 'pvp');
check($o->winner === null, 'Summons excluded from rank tiebreak');
$o = battle([1000 => skill(['pow' => 10000])], [unit('a')], [unit('b', 1000, ['monster' => true, 'moneyhold' => 100])], 2, 'pve');
check($o->winner === 0 && $o->rewardCandidates[0][0]['money'] === 100, 'PvE candidate rewards');
$o = battle([1000 => skill(['pow' => 10000])], [unit('a')], [unit('b', 1000, ['monster' => true, 'moneyhold' => 100])], 2, 'simulation');
check($o->rewardCandidates === [[], []], 'Simulation has no rewards');
$u = new Combatant('a', 0, unit('a'));
$other = new Combatant('b', 1, unit('b'));
$other->v['state'] = 2;
check(Conditions::evaluate(1617, 100, 0, $u, [[$u], [$other]], [0, 0], $s, new SequenceRandom), 'Enemy poison percentage repaired');
check(Conditions::evaluate(1300, 100, 0, $u, [[$u], [$other]], [0, 0], $s, new SequenceRandom), 'Stat comparison repaired');
$u->casting = 1000;
check(Conditions::evaluate(1506, 0, 0, $u, [[$u], [$other]], [0, 0], [1000 => ['type' => 0]], new SequenceRandom), '1506 counts casts rather than charges');
$a = SnapshotFactory::character(unit('a'), ['weapon' => ['type' => 'Bow', 'atk' => [10, 20], 'P_STR' => 5, 'M_MAXHP' => 10]], [['p_maxhp' => 30]]);
check($a['maxhp'] === 1130 && $a['str'] === 105 && $a['weaponType'] === 'Bow', 'Equipment and normalized passive modifiers');
$o = battle([1000 => skill(), 2000 => skill(['target' => ['self', 'individual', 1], 'pow' => 0, 'summon' => 5000, 'quick' => 1])], [unit('a', 2000)], [unit('b')], 1, 'simulation', [5000 => unit('proto')]);
check(count($o->teams[0]) === 2 && $o->teams[0][1]['summon'] === true && $o->teams[0][1]['progress'] === 100.1, 'Summon joins independently and quick acts');
check($o->teams[0][1]['maxhp'] === 1100, 'Summon strength formula');
$o = battle([1000 => skill(['pow' => 10000])], [unit('a')], [unit('b', 1000, ['SPECIAL' => ['Barrier' => true]])]);
check($o->teams[1][0]['hp'] === 1000, 'Barrier absorbs single hit');
try {
    battle($s, [unit('a', 123456)], [unit('b')]);
    check(false, 'Unknown skill must fail');
} catch (InvalidArgumentException) {
    check(true, 'Unknown skill rejected');
}
// Numerical oracles for named effects and interaction boundaries.
$o = battle([1020 => skill(), 1000 => skill()], [unit('a', 1020)], [unit('b')]);
check($o->teams[1][0]['hp'] === 1000 && $o->teams[1][0]['sp'] === 900, 'ManaBreak targets SP only');
$o = battle([1021 => skill(), 1000 => skill()], [unit('a', 1021)], [unit('b')]);
check($o->teams[1][0]['hp'] === 900 && $o->teams[1][0]['sp'] === 900, 'SoulBreak both resources');
$o = battle([1022 => skill(), 1000 => skill()], [unit('a', 1022, ['position' => 'back'])], [unit('b')]);
check($o->teams[1][0]['hp'] === 600 && $o->teams[0][0]['position'] === 'front', 'ChargeAttack back multiplier and move');
$o = battle([1023 => skill(), 1000 => skill()], [unit('a', 1023)], [unit('b')]);
check($o->teams[1][0]['hp'] === 700 && $o->teams[0][0]['position'] === 'back', 'HitAway front multiplier and move');
$o = battle([1024 => skill(), 1000 => skill()], [unit('a', 1024, ['hp' => 200])], [unit('b')]);
check($o->teams[0][0]['hp'] === 600 && $o->teams[1][0]['hp'] === 600, 'LifeDivision equalizes resources');
$o = battle([1024 => skill(), 1000 => skill()], [unit('a', 1024, ['hp' => 200])], [unit('b', 1000, ['hp' => 5000, 'maxhp' => 5000])]);
check($o->teams[0][0]['hp'] === 700 && $o->teams[1][0]['hp'] === 4500, 'LifeDivision correction above1000');
$o = battle([1116 => skill(), 1000 => skill()], [unit('a', 1116, ['hp' => 250])], [unit('b')]);
check($o->teams[1][0]['hp'] === 250, 'Punish missing life');
$o = battle([1200 => skill(), 1000 => skill()], [unit('a', 1200)], [unit('b', 1000, ['state' => 2])]);
check($o->teams[1][0]['hp'] === 400, 'PoisonBlow sixfold');
$o = battle([2030 => skill(), 1000 => skill()], [unit('a', 2030, ['hp' => 500])], [unit('b')]);
check($o->teams[0][0]['hp'] === 600 && $o->teams[1][0]['hp'] === 900, 'LifeDrain');
$o = battle([2090 => skill(), 1000 => skill()], [unit('a', 2090, ['sp' => 500])], [unit('b', 1000, ['def' => [100, 500, 100, 500]])]);
check($o->teams[0][0]['sp'] === 600 && $o->teams[1][0]['sp'] === 900, 'EnergyDrain ignores defense');
$o = battle([2055 => skill(), 1000 => skill()], [unit('a', 2055), unit('dead', 1000, ['hp' => 0])], [unit('b')]);
check($o->teams[1][0]['hp'] === 800, 'SoulRevenge counts allies dead');
$o = battle([2056 => skill(['target' => ['friend', 'individual', 1], 'priority' => 'Dead', 'DownMAXHP' => 50]), 1000 => skill()], [unit('a', 2056), unit('dead', 1000, ['hp' => 0])], [unit('b')]);
check($o->teams[0][1]['hp'] === 500 && $o->teams[0][1]['maxhp'] === 500, 'ZombieRevival stats before full heal');
$o = battle([2057 => skill(['target' => ['self', 'individual', 1], 'UpSTR' => 100]), 1000 => skill()], [unit('a', 2057, ['hp' => 500])], [unit('b')]);
check($o->teams[0][0]['hp'] === 1000 && $o->teams[0][0]['str'] === 200 && $o->teams[0][0]['SPECIAL']['Metamo'], 'Metamorphosis threshold and once flag');
$o = battle([3012 => skill(['target' => ['self', 'individual', 1]]), 1000 => skill()], [unit('a', 3012, ['hp' => 1, 'sp' => 0])], [unit('b')]);
check($o->teams[0][0]['hp'] === 1 && $o->teams[0][0]['sp'] === 700, 'LifeConvert nonlethal sacrifice');
$o = battle([3013 => skill(['target' => ['self', 'individual', 1]]), 1000 => skill()], [unit('a', 3013, ['hp' => 800, 'sp' => 200])], [unit('b')]);
check($o->teams[0][0]['hp'] === 200 && $o->teams[0][0]['sp'] === 800, 'EnergyExchange percentages');
$o = battle([3120 => skill(['target' => ['self', 'individual', 1]]), 1000 => skill()], [unit('a', 3120, ['hp' => 200])], [unit('b')]);
check($o->teams[0][0]['hp'] === 350, 'FirstAid fixed plus10percent');
$o = battle([5060 => skill(), 1000 => skill()], [unit('a', 5060, ['def' => [20, 0, 20, 0]])], [unit('b', 1000, ['def' => [50, 0, 50, 0]])]);
check($o->teams[0][0]['def'][0] === 44 && $o->teams[1][0]['def'][0] === 35, 'ArmorSnatch asymmetric percent math');
$o = battle([1000 => skill(['poison' => 1, 'pow' => 0])], [unit('a')], [unit('b')]);
check($o->teams[1][0]['state'] === 2, 'Poison without resistance preserves reference guaranteed infliction');
$o = battle([1000 => skill(['poison' => 100, 'pow' => 0])], [unit('a')], [unit('b', 1000, ['SPECIAL' => ['PoisonResist' => 100]])]);
check($o->teams[1][0]['state'] === 0, 'Poison immunity');
$o = battle([1000 => skill(['DownMAXHP' => 50, 'delay' => 90, 'pow' => 0])], [unit('a')], [unit('boss', 1000, ['boss' => true, 'monster' => true])]);
check($o->teams[1][0]['maxhp'] === 750 && $o->teams[1][0]['progress'] === 70.0, 'Boss half resource debuff and third delay');
$o = battle([1000 => skill(['pow' => 10000])], [unit('a')], [unit('summon', 1000, ['summon' => true, 'monster' => true]), unit('b')]);
check(count($o->teams[1]) === 1 && count($o->retired) === 1, 'Dead summon removed and archived');
$o = battle([1000 => skill()], [unit('a'), unit('dead', 1000, ['hp' => 0])], [unit('boss', 1000, ['boss' => true, 'monster' => true, 'exphold' => 300, 'moneyhold' => 40])], 1, 'boss');
check($o->rewardCandidates[0][0]['experience'] === 30 && $o->rewardCandidates[0][0]['recipients'] === ['a'] && $o->rewardCandidates[0][0]['bossDamage'] === 100, 'Per-action boss damage XP and living recipients');
$o = battle([1000 => skill(['pow' => 10000])], [unit('a'), unit('a2')], [unit('boss', 1000, ['boss' => true, 'monster' => true, 'exphold' => 301, 'moneyhold' => 40])], 1, 'boss');
check($o->rewardCandidates[0][0]['experience'] === 602 && $o->rewardCandidates[0][0]['experiencePerRecipient'] === 301, 'Boss kill includes damage XP plus held XP once');
$o = battle([1000 => skill(['target' => ['enemy', 'all', 1], 'pow' => 10000])], [unit('a'), unit('a2')], [unit('b', 1000, ['monster' => true, 'exphold' => 3]), unit('b2', 1000, ['monster' => true, 'exphold' => 3])], 1, 'pve');
check($o->rewardCandidates[0][0]['experiencePerRecipient'] === 3, 'Aggregate XP before dividing across survivors');
$r = new SeededRandom(100);
$r->integer(1, 10);
$restored = SeededRandom::fromState($r->state());
check($r->integer(1, 10000) === $restored->integer(1, 10000), 'RNG state restoration');
try {
    new BattleEngine([1000 => skill(['unimplementedEffect' => true])]);
    check(false, 'Unknown effect must fail');
} catch (InvalidArgumentException) {
    check(true, 'Unknown effect rejected');
}
$u = new Combatant('moving', 0, unit('moving'));
$u->v['position'] = 'back';
check(! Conditions::evaluate(1410, 1, 0, $u, [[$u], []], [0, 0], $s, new SequenceRandom), 'Front-row condition follows current formation');
$o = (new BattleEngine([1000 => skill(['pow' => 10000])]))->simulate(new BattleSnapshot([[unit('a')], [unit('b')]], maxActions: 1, maxSteps: 1), new SequenceRandom);
check($o->winner === 0 && $o->reason === 'elimination', 'Elimination on final safety-bound step');
$o = (new BattleEngine([1000 => skill(['pow' => 0])]))->simulate(new BattleSnapshot([[unit('a'), unit('a2')], [unit('b')]], 'pvp', maxActions: 1, maxSteps: 1), new SequenceRandom);
check($o->winner === 0 && $o->reason === 'survivor_limit', 'Rank comparison on exact step/action bound');
$thresholds = array_fill(1, 49, 20);
$o = battle([1000 => skill(['pow' => 10000])], [unit('a', 1000, ['exp' => 10, 'experienceThresholds' => $thresholds])], [unit('b', 1000, ['monster' => true, 'exphold' => 1000])], 1, 'pve');
check($o->teams[0][0]['level'] === 2 && $o->teams[0][0]['exp'] === 0, 'Local per-action level-up once and excess XP reset');
$o = battle([1000 => skill(['pow' => 10000])], [unit('a', 1000, ['exp' => 10, 'experienceThresholds' => $thresholds])], [unit('b', 1000, ['monster' => true, 'exphold' => 1000])], 1, 'simulation');
check($o->teams[0][0]['level'] === 1 && $o->teams[0][0]['exp'] === 10, 'Simulation does not change local growth');
$o = battle([1000 => skill(), 2000 => skill(['charge' => [10000, 0], 'sp' => 20, 'type' => 1])], [unit('a')], [unit('caster', 2000, ['spd' => 100000, 'hp' => 100])], 1);
check($o->teams[1][0]['casting'] === null && $o->teams[1][0]['state'] === 1 && $o->teams[1][0]['sp'] === 1000, 'Death interrupts pending cast without spending SP');
$o = battle([1000 => skill()], [unit('a', 1000, ['hp' => 500, 'state' => 2, 'SPECIAL' => ['HpRegen' => 10]])], [unit('b')]);
check($o->teams[0][0]['hp'] === 499, 'Regeneration occurs before nonlethal poison');
$o = battle([1000 => skill(['MagicCircleDeleteTeam' => 1, 'sp' => 10])], [unit('a')], [unit('b')]);
check($o->teams[0][0]['sp'] === 1000 && $o->teams[1][0]['hp'] === 1000, 'Insufficient circles do not spend SP');
$o = battle([1000 => skill(['target' => ['friend', 'individual', 1], 'priority' => 'Dead', 'sp' => 10])], [unit('a')], [unit('b')]);
check($o->teams[0][0]['sp'] === 990 && count(array_filter($o->events, fn ($e) => ($e['reason'] ?? null) === 'no_target')) === 1, 'No valid resurrection target is safe and still costs SP');
$rules = [['condition' => 1001, 'quantity' => 0, 'skill' => 9000], ['condition' => 1000, 'quantity' => 0, 'skill' => 2000], ['condition' => 1920, 'quantity' => 1, 'skill' => 1000]];
$o = battle([1000 => skill(), 2000 => skill(['pow' => 500])], [unit('a', 1000, ['tactics' => $rules])], [unit('b')]);
check($o->teams[1][0]['hp'] === 900 && $o->teams[0][0]['conditionCounts'] === [2 => 1], 'AND chains fail together and count only selected group');
$catalog = [1000 => skill(), 2000 => skill(['target' => ['self', 'individual', 1], 'pow' => 0, 'summon' => 5000])];
$o = (new BattleEngine($catalog, [5000 => unit('proto')]))->simulate(new BattleSnapshot([[unit('a', 2000)], [unit('b')]], 'simulation', maxActions: 1, maxUnits: 2), new SequenceRandom);
check(count($o->teams[0]) === 1 && count(array_filter($o->events, fn ($e) => $e['type'] === 'SummonBlocked')) === 1, 'Summon safety bound is explicit');
echo "Combat: $checks checks passed\n";
