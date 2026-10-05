<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** One isolated simulation. No service container, persistence, wall clock, or output. */
final class BattleRun
{
    use Effects;

    private array $teams = [[], []];

    private array $events = [];

    private array $circles = [0, 0];

    private array $damage = [0, 0];

    private array $rewards = [[], []];

    private float $tick = 0;

    private int $actions = 0;

    private int $summonSerial = 0;

    private ?int $actingTeam = null;

    private array $ids = [];

    private array $retired = [];

    private array $pending = [[], []];

    private array $bossLastHp = [];

    private array $rewardTargets = [];

    public function __construct(private readonly array $skills, private readonly array $summons, private readonly BattleSnapshot $snapshot, private readonly RandomSource $rng)
    {
        $this->circles = $snapshot->magicCircles;
        foreach ($snapshot->teams as $team => $units) {
            foreach ($units as $i => $data) {
                $id = (string) ($data['id'] ?? "$team:$i");
                if (isset($this->ids[$id])) {
                    throw new \InvalidArgumentException("Duplicate unit ID $id");
                }
                $this->ids[$id] = true;
                $unit = new Combatant($id, $team, $data);
                $this->validateTactics($unit);
                $this->teams[$team][] = $unit;
                if (! empty($unit->v['boss'])) {
                    $this->bossLastHp[$id] = $unit->v['hp'];
                }
            }
        }
        if (count($this->ids) > $snapshot->maxUnits) {
            throw new \InvalidArgumentException('Too many units');
        }
    }

    private function validateTactics(Combatant $unit): void
    {
        if (count($unit->v['tactics']) > 100) {
            throw new \InvalidArgumentException('Too many tactics');
        }
        foreach ($unit->v['tactics'] as $row) {
            if (! isset($row['condition'],$row['skill']) || ! in_array((int) $row['condition'], Conditions::IDS, true)) {
                throw new \InvalidArgumentException('Unknown tactic condition');
            }
            $id = (int) $row['skill'];
            if ($id === 9000) {
                continue;
            }
            if (! isset($this->skills[$id])) {
                throw new \InvalidArgumentException("Unknown skill $id");
            }
            $s = $this->skills[$id];
            if ($id === 3113) {
                throw new \DomainException('Unsupported skill 3113 Berserk: source supplies no effect or numeric rule');
            }
            if (! empty($s['passive']) || ! isset($s['target']) || ! in_array($s['target'][0], ['friend', 'enemy', 'self', 'all'], true) || ! in_array($s['target'][1], ['individual', 'multi', 'all'], true) || (int) $s['target'][2] < 1 || (int) $s['target'][2] > 100) {
                throw new \InvalidArgumentException("Invalid active skill $id");
            }
            foreach ((array) ($s['summon'] ?? []) as $no) {
                if (! isset($this->summons[$no])) {
                    throw new \InvalidArgumentException("Missing summon $no for skill $id");
                }
            }
        }
    }

    public function run(): BattleOutcome
    {
        $initialRandom = $this->rng->state();
        $this->event('BattleStarted', ['mode' => $this->snapshot->mode, 'contentVersion' => $this->snapshot->contentVersion, 'rulesVersion' => 'hof-combat-type1-v1', 'random' => $initialRandom]);
        $reason = 'action_limit';
        $winner = null;
        for ($step = 0; $step < $this->snapshot->maxSteps; $step++) {
            $alive = array_map(static fn (array $t): int => count(array_filter($t, static fn (Combatant $u): bool => $u->alive())), $this->teams);
            if (! $alive[0] || ! $alive[1]) {
                $winner = $alive[0] ? 0 : ($alive[1] ? 1 : null);
                $reason = 'elimination';
                break;
            }
            if ($this->actions >= $this->snapshot->maxActions) {
                if ($this->snapshot->mode === 'pvp') {
                    $n = array_map(static fn (array $t): int => count(array_filter($t, static fn (Combatant $u): bool => $u->alive() && ! $u->v['monster'] && ! $u->v['summon'])), $this->teams);
                    $winner = $n[0] === $n[1] ? null : ($n[0] > $n[1] ? 0 : 1);
                    $reason = 'survivor_limit';
                }
                break;
            }
            $actor = $this->nextActor();
            $this->actingTeam = $actor->team;
            $this->event('ActorSelected', ['actor' => $actor->id, 'progress' => $actor->progress]);
            $this->rewardTargets = [];
            $this->act($actor);
            $this->judgeDeaths();
            $this->awardCandidates();
            if ($step === $this->snapshot->maxSteps - 1) {
                $reason = 'step_limit';
            }
        }
        $alive = array_map(static fn (array $team): int => count(array_filter($team, static fn (Combatant $u): bool => $u->alive())), $this->teams);
        if (! $alive[0] || ! $alive[1]) {
            $winner = $alive[0] ? 0 : ($alive[1] ? 1 : null);
            $reason = 'elimination';
        } elseif ($this->actions >= $this->snapshot->maxActions) {
            $reason = 'action_limit';
            if ($this->snapshot->mode === 'pvp') {
                $survivors = array_map(static fn (array $team): int => count(array_filter($team, static fn (Combatant $u): bool => $u->alive() && ! $u->v['monster'] && ! $u->v['summon'])), $this->teams);
                $winner = $survivors[0] === $survivors[1] ? null : ($survivors[0] > $survivors[1] ? 0 : 1);
                $reason = 'survivor_limit';
            }
        }
        $this->event('BattleFinished', ['winner' => $winner, 'reason' => $reason, 'actions' => $this->actions, 'damage' => $this->damage]);
        $teams = array_map(static fn (array $t): array => array_map(static fn (Combatant $u): array => $u->export(), $t), $this->teams);
        $rewards = in_array($this->snapshot->mode, ['pvp', 'simulation'], true) ? [[], []] : $this->rewards;

        return new BattleOutcome($winner, $reason, $teams, $this->events, $this->actions, $this->rng->state(), $rewards, $this->damage, $this->snapshot->mode, $this->snapshot->contentVersion, retired: $this->retired, magicCircles: $this->circles);
    }

    private function nextActor(): Combatant
    {
        $minimum = INF;
        $candidates = [];
        foreach ($this->teams as $team) {
            foreach ($team as $u) {
                if (! $u->alive()) {
                    continue;
                }
                $distance = (100 - $u->progress) / $u->rate();
                if ($distance < $minimum) {
                    $minimum = $distance;
                    $candidates = [$u];
                } elseif ($distance === $minimum) {
                    $candidates[] = $u;
                }
            }
        }
        if ($minimum >= 0) {
            $this->tick += $minimum;
            foreach ($this->teams as $team) {
                foreach ($team as $u) {
                    if ($u->alive()) {
                        $u->progress += $minimum * $u->rate();
                    }
                }
            }
        }

        return $candidates[$this->rng->integer(0, count($candidates) - 1)];
    }

    private function act(Combatant $u): void
    {
        $id = $u->casting;
        if ($id === null) {
            foreach (['hp' => 'HpRegen', 'sp' => 'SpRegen'] as $resource => $special) {
                if (! empty($u->v['SPECIAL'][$special])) {
                    $this->resource($u, $resource, (int) round($u->v['max'.$resource] * $u->v['SPECIAL'][$special] / 100), 'regeneration');
                }
            }
            $this->poisonDamage($u);
            $matches = true;
            $rows = [];
            foreach ($u->v['tactics'] as $index => $row) {
                $rows[] = $index;
                // Evaluate each row in order, preserving RNG consumption only up to first failed AND.
                if ($matches) {
                    $matches = Conditions::evaluate((int) $row['condition'], (int) ($row['quantity'] ?? 0), $index, $u, $this->teams, $this->circles, $this->skills, $this->rng);
                }
                if ((int) $row['skill'] === 9000) {
                    continue;
                }
                if ($matches) {
                    $id = (int) $row['skill'];
                    foreach ($rows as $r) {
                        $u->counts[$r] = ($u->counts[$r] ?? 0) + 1;
                    } break;
                }
                $matches = true;
                $rows = [];
            }
        }
        $this->actions++;
        if ($id === null) {
            $u->progress = 0;
            $this->event('ActionSkipped', ['actor' => $u->id, 'reason' => 'no_matching_tactic']);

            return;
        }
        $s = $this->skills[$id];
        if (! empty($s['limit']) && ! $u->v['monster'] && empty($s['limit'][$u->v['weaponType'] ?? ''])) {
            $this->fail($u, $id, 'weapon');

            return;
        }
        if ($u->v['sp'] < (int) ($s['sp'] ?? 0)) {
            $this->fail($u, $id, 'sp');

            return;
        }
        if (! empty($s['charge'][0]) && $u->casting === null) {
            $u->casting = $id;
            $this->delay($u, (float) $s['charge'][0]);
            $this->actions--;
            $this->event('CastStarted', ['actor' => $u->id, 'skill' => $id, 'type' => (int) ($s['type'] ?? 0)]);

            return;
        }
        $u->actions++;
        $circleCost = (int) ($s['MagicCircleDeleteTeam'] ?? 0);
        if ($circleCost > $this->circles[$u->team]) {
            $this->fail($u, $id, 'magic_circles');

            return;
        }
        if ($circleCost) {
            $this->circles[$u->team] -= $circleCost;
            $this->event('MagicCirclesChanged', ['team' => $u->team, 'value' => $this->circles[$u->team]]);
        }
        $this->resource($u, 'sp', -(int) ($s['sp'] ?? 0), 'skill_cost');
        $u->casting = null;
        $this->event('SkillUsed', ['actor' => $u->id, 'skill' => $id]);
        if (! empty($s['sacrifice'])) {
            $this->resource($u, 'hp', -(int) (ceil($u->v['maxhp'] * $s['sacrifice'] / 100) * ($u->v['position'] === 'front' ? 1 : 2)), 'sacrifice');
        }
        $candidate = match ($s['target'][0]) {
            'self' => [$u], 'friend' => $this->teams[$u->team], 'enemy' => $this->teams[1 - $u->team], 'all' => array_merge($this->teams[$u->team], $this->teams[1 - $u->team])
        };
        $this->rewardTargets = $candidate;
        $style = $s['target'][1];
        $repeat = (int) $s['target'][2];
        if ($style === 'all') {
            foreach ($candidate as $target) {
                if (! $target->alive() && ! in_array($s['priority'] ?? '', ['Dead', '死亡'], true)) {
                    continue;
                }
                for ($i = 0; $i < $repeat; $i++) {
                    $this->apply($id, $s, $u, $target);
                }
            }
        } elseif ($style === 'individual') {
            $target = $this->select($candidate, $s);
            if ($target) {
                $target = $this->guard($target, $candidate, $s);
            }
            for ($i = 0; $i < $repeat; $i++) {
                $this->apply($id, $s, $u, $target);
            }
        } else {
            for ($i = 0; $i < $repeat; $i++) {
                $target = $this->select($candidate, $s);
                if ($target) {
                    $target = $this->guard($target, $candidate, $s);
                } $this->apply($id, $s, $u, $target);
            }
        }
        if (! empty($s['umove'])) {
            $this->move($u, $s['umove']);
        }
        $this->judgeDeaths();
        $u->progress = 0;
        if (! empty($s['charge'][1])) {
            $this->delay($u, (float) $s['charge'][1]);
        }
    }

    private function fail(Combatant $u, int $skill, string $reason): void
    {
        $u->casting = null;
        $u->progress = 0;
        $this->event('SkillFailed', ['actor' => $u->id, 'skill' => $skill, 'reason' => $reason]);
    }

    private function select(array $candidate, array $s): ?Combatant
    {
        $p = $s['priority'] ?? '';
        $pool = array_values(array_filter($candidate, static fn (Combatant $u): bool => $p === 'Dead' || $p === '死亡' ? ! $u->alive() && ! $u->v['summon'] && empty($u->v['boss']) : $u->alive()));
        if ($p === 'Summon') {
            $pool = array_values(array_filter($pool, static fn (Combatant $u): bool => (bool) $u->v['summon']));
        }
        if ($p === 'Charge') {
            $pool = array_values(array_filter($pool, static fn (Combatant $u): bool => $u->casting !== null));
        }
        if ($p === 'Back') {
            $back = array_values(array_filter($pool, static fn (Combatant $u): bool => $u->v['position'] === 'back'));
            if ($back) {
                $pool = $back;
            }
        }
        if (! $pool) {
            return null;
        }
        if ($p === 'LowHpRate') {
            $target = $pool[0];
            foreach ($pool as $u) {
                if ($u->percent('hp') < $target->percent('hp')) {
                    $target = $u;
                }
            }

            return $target;
        }

        return $pool[$this->rng->integer(0, count($pool) - 1)];
    }

    private function guard(Combatant $target, array $candidate, array $s): Combatant
    {
        if (! empty($s['invalid']) || ! empty($s['support']) || $target->v['position'] === 'front' || ! $target->alive()) {
            return $target;
        }
        $front = array_values(array_filter($candidate, static fn (Combatant $u): bool => $u->alive() && $u->v['hp'] > 1 && $u->v['position'] === 'front' && $u->team === $target->team));
        for ($i = count($front) - 1; $i > 0; $i--) {
            $j = $this->rng->integer(0, $i);
            [$front[$i],$front[$j]] = [$front[$j], $front[$i]];
        }
        foreach ($front as $u) {
            $policy = $u->v['guard'];
            $pass = match ($policy) {
                'never' => false, 'life25','life50','life75' => $u->percent('hp') > (int) substr($policy, 4), 'prob25','prob50','prob75' => $this->rng->integer(1, 100) < (int) substr($policy, 4), default => true
            };
            if ($pass) {
                $this->event('Guarded', ['actor' => $u->id, 'target' => $target->id]);

                return $u;
            }
        }

        return $target;
    }

    private function event(string $type, array $data = []): void
    {
        $this->events[] = ['sequence' => count($this->events), 'tick' => $this->tick, 'type' => $type] + $data;
    }

    private function judgeDeaths(): void
    {
        foreach ($this->teams as $team) {
            foreach ($team as $u) {
                if ($u->v['hp'] > 0 || ! $u->alive()) {
                    continue;
                }
                $u->v['state'] = 1;
                $u->casting = null;
                $this->event('ActorDied', ['actor' => $u->id]);
                if ($u->v['monster'] && ! $u->v['summon']) {
                    $creditTeam = 1 - $u->team;
                    $this->pending[$creditTeam][] = ['unit' => $u->id, 'experience' => (int) ($u->v['exphold'] ?? 0), 'money' => (int) ($u->v['moneyhold'] ?? 0), 'item' => $u->v['itemdrop'] ?? null];
                    $u->v['exphold'] = (int) round(($u->v['exphold'] ?? 0) / 2);
                    $u->v['moneyhold'] = 0;
                    $u->v['itemdrop'] = null;
                }
            }
        }
        foreach ($this->teams as $index => $team) {
            foreach ($team as $key => $u) {
                if (! $u->alive() && $u->v['summon']) {
                    $this->retired[] = $u->export();
                    unset($this->teams[$index][$key]);
                }
            }
            $this->teams[$index] = array_values($this->teams[$index]);
        }
    }

    private function awardCandidates(): void
    {
        foreach ($this->rewardTargets as $u) {
            if (empty($u->v['boss'])) {
                continue;
            }
            $difference = max(0, ($this->bossLastHp[$u->id] ?? $u->base['hp']) - $u->v['hp']);
            $this->bossLastHp[$u->id] = $u->v['hp'];
            if ($difference) {
                $this->pending[1 - $u->team][] = ['unit' => $u->id, 'experience' => (int) ceil(($u->base['exphold'] ?? 0) * $difference / $u->base['maxhp']), 'money' => 0, 'item' => null, 'bossDamage' => $difference];
            }
        }
        foreach ([0, 1] as $team) {
            if (! $this->pending[$team]) {
                continue;
            }
            $batch = ['action' => $this->actions, 'units' => [], 'experience' => 0, 'recipients' => [], 'experiencePerRecipient' => 0, 'money' => 0, 'items' => [], 'bossDamage' => 0];
            foreach ($this->pending[$team] as $entry) {
                $batch['units'][] = $entry['unit'];
                $batch['experience'] += $entry['experience'];
                $batch['money'] += $entry['money'];
                $batch['bossDamage'] += $entry['bossDamage'] ?? 0;
                if ($entry['item'] !== null) {
                    $batch['items'][$entry['item']] = ($batch['items'][$entry['item']] ?? 0) + 1;
                }
            }
            $batch['units'] = array_values(array_unique($batch['units']));
            foreach ($this->teams[$team] as $u) {
                if ($u->alive() && ! $u->v['monster'] && ! $u->v['summon']) {
                    $batch['recipients'][] = $u->id;
                }
            }
            if ($batch['recipients']) {
                $batch['experiencePerRecipient'] = (int) ceil($batch['experience'] / count($batch['recipients']));
            }
            if (in_array($this->snapshot->mode, ['pve', 'boss'], true) && $batch['experiencePerRecipient'] > 0) {
                foreach ($this->teams[$team] as $unit) {
                    if (! in_array($unit->id, $batch['recipients'], true) || ! isset($unit->v['experienceThresholds']) || $unit->v['level'] >= 50) {
                        continue;
                    }
                    $unit->v['exp'] = (int) ($unit->v['exp'] ?? 0) + $batch['experiencePerRecipient'];
                    $required = $unit->v['experienceThresholds'][$unit->v['level']];
                    if ($unit->v['exp'] >= $required) {
                        $unit->v['exp'] = 0;
                        $unit->v['level']++;
                        $this->event('ActorLeveled', ['actor' => $unit->id, 'level' => $unit->v['level']]);
                    }
                }
            }
            $this->rewards[$team][] = $batch;
            $this->pending[$team] = [];
        }
    }
}
