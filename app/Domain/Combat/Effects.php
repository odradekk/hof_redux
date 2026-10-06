<?php

declare(strict_types=1);

namespace App\Domain\Combat;

/** The finite skill dispatch, translated from class.skill_effect.php. */
trait Effects
{
    private function apply(int $id, array $s, Combatant $u, ?Combatant $t): void
    {
        if (! $t) {
            $this->event('SkillFailed', ['actor' => $u->id, 'skill' => $id, 'reason' => 'no_target']);

            return;
        }
        $this->event('TargetSelected', ['actor' => $u->id, 'target' => $t->id, 'skill' => $id]);
        switch ($id) {
            case 1020: $this->resource($t, 'sp', -$this->basicDamage($s, $u, $t), 'mana_break');

                return;
            case 1021: $n = $this->basicDamage($s, $u, $t);
                $this->resource($t, 'hp', -$n, 'soul_break');
                $this->resource($t, 'sp', -$n, 'soul_break');

                return;
            case 1022: $this->resource($t, 'hp', -$this->basicDamage($s, $u, $t, $u->v['position'] !== 'front' ? 4 : 1), 'charge_attack');
                $this->move($u, 'front');

                return;
            case 1023: $this->resource($t, 'hp', -$this->basicDamage($s, $u, $t, $u->v['position'] === 'front' ? 3 : 1), 'hit_and_away');
                $this->move($u, 'back');

                return;
            case 1024: case 1025:
                $r = $id === 1024 ? 'hp' : 'sp';
                $n = (int) round(abs($t->v[$r] - $u->v[$r]) * 0.5);
                if ($u->v[$r] <= $t->v[$r]) {
                    if ($n >= 1000) {
                        $n = 500;
                    } $this->resource($t, $r, -$n, 'division');
                    $this->resource($u, $r, $n, 'division');
                } else {
                    $this->resource($u, $r, -$n, 'division');
                    $this->resource($t, $r, $n, 'division');
                }

                return;
            case 1116: $this->resource($t, 'hp', -($u->v['maxhp'] - $u->v['hp']), 'punish');

                return;
            case 1119: if ($u !== $t) {
                $this->statusChanges($s, $t);
            }

                return;
            case 1200: $this->resource($t, 'hp', -$this->basicDamage($s, $u, $t, $t->v['state'] === 2 ? 6 : 1), 'poison_blow');

                return;
            case 1208: $this->poisonDamage($t, (log(($u->v['int'] + 22) / 10) - 0.8) / 0.85);

                return;
            case 1209: if ($t->v['state'] === 2) {
                $this->statusChanges($s, $t);
                $this->normal($t);
            }

                return;
            case 1220: $old = $t->v['SPECIAL']['PoisonResist'] ?? 0;
                $t->v['SPECIAL']['PoisonResist'] = $old + (int) round((100 - $old) * 0.5);
                $this->event('SpecialChanged', ['target' => $t->id, 'special' => 'PoisonResist', 'value' => $t->v['SPECIAL']['PoisonResist']]);

                return;
            case 2030: case 2031: case 5002:
                if ($u === $t) {
                    return;
                }
                $n = $this->basicDamage($s, $u, $t, 1, $id === 5002);
                $this->resource($t, 'hp', -$n, 'life_drain');
                $this->resource($u, 'hp', $n, 'life_drain');

                return;
            case 2032: if ($this->rng->integer(1, 100) > 50) {
                $this->resource($t, 'hp', -$t->v['hp'], 'death_knell');
            } else {
                $this->event('EffectResisted', ['target' => $t->id, 'skill' => $id]);
            }

                return;
            case 2055:
                $dead = count(array_filter($this->teams[$u->team], static fn (Combatant $c): bool => ! $c->alive() || ! empty($c->v['SPECIAL']['Undead'])));
                $this->resource($t, 'hp', -$this->basicDamage($s, $u, $t, $dead + 1), 'soul_revenge');

                return;
            case 2056: if (! $t->alive() && $this->normal($t)) {
                $this->statusChanges($s, $t);
                $this->resource($t, 'hp', $t->v['maxhp'], 'zombie_revival');
            }

                return;
            case 2057:
                if ($t->percent('hp') > 60 || ! empty($t->v['SPECIAL']['Metamo'])) {
                    $this->event('SkillFailed', ['actor' => $u->id, 'skill' => $id, 'reason' => 'metamorphosis_condition']);

                    return;
                }
                $t->v['SPECIAL']['Metamo'] = true;
                $t->v['img'] = (int) ($t->v['gender'] ?? 0) === 0 ? 'mon_110r.gif' : 'mon_149r.gif';
                $this->event('AppearanceChanged', ['target' => $t->id, 'image' => $t->v['img']]);
                $this->statusChanges($s, $t);
                $this->resource($t, 'hp', (int) round($t->v['maxhp'] / 2), 'metamorphosis');

                return;
            case 2090: case 2091:
                if ($u === $t) {
                    return;
                }
                $n = $this->basicDamage($s, $u, $t, 1, true);
                $this->resource($t, 'sp', -$n, 'energy_drain');
                $this->resource($u, 'sp', $n, 'energy_drain');

                return;
            case 2110: case 2111: if ($t->casting !== null) {
                $this->delay($t, (float) ($s['delay'] ?? 0));
            }

                return;
            case 3005: $this->resource($t, 'hp', $this->healing($s, $u) * ($t->percent('hp') <= 30 ? 2 : 1), 'progressive_heal');

                return;
            case 3010: case 3011: $this->resource($t, 'sp', (int) ceil($t->v['maxsp'] * ($id === 3010 ? 0.3 : 0.5)), 'mana_recharge');

                return;
            case 3012: $this->resource($t, 'hp', -(int) ceil($t->v['maxhp'] * 0.3), 'life_convert', true);
                $this->resource($t, 'sp', (int) ceil($t->v['maxsp'] * 0.7), 'life_convert');

                return;
            case 3013:
                $hp = (int) round(floor($t->percent('sp')) / 100 * $t->v['maxhp']);
                $sp = (int) round(floor($t->percent('hp')) / 100 * $t->v['maxsp']);
                $this->resource($t, 'hp', $hp - $t->v['hp'], 'energy_exchange');
                $this->resource($t, 'sp', $sp - $t->v['sp'], 'energy_exchange');

                return;
            case 3020: $this->statusChanges(['UpMAXSP' => 20], $t);

                return;
            case 3040: case 5030: case 5063: if (! $t->alive() && $this->normal($t)) {
                $this->resource($t, 'hp', $this->healing($s, $u), 'resurrection');
            }

                return;
            case 3050: if ($t !== $u && $t->casting === null) {
                $this->accelerate($t, 101);
            }

                return;
            case 3055: if ($t->casting !== null && (int) ($this->skills[$t->casting]['type'] ?? 0) === 1) {
                $this->accelerate($t, 60);
            }

                return;
            case 3060: case 5067: $t->v['SPECIAL']['Barrier'] = true;
                $this->event('SpecialChanged', ['target' => $t->id, 'special' => 'Barrier', 'value' => true]);

                return;
            case 3113: throw new \DomainException('Berserk 3113 has no source effect and is not playable');
            case 3120: case 3121: $this->resource($t, 'hp', (int) ceil(50 + $t->v['maxhp'] * ($id === 3120 ? 0.1 : 0.2)), 'self_recovery');

                return;
            case 3122: $this->resource($t, 'hp', (int) ceil(($u->v['maxhp'] - $u->v['hp']) * 0.6), 'hyper_recovery');

                return;
            case 3300: case 3301: case 3302: case 3303: case 3304: case 3305: case 3306: case 3307: case 3308: case 3310:
                if ($t->v['summon']) {
                    $this->statusChanges($s, $t);
                }

                return;
            case 3900: $this->poison($u, 100);

                return;
            case 3901: $this->resource($u, 'hp', -9999, 'self_destruct');

                return;
            case 4000: $this->move($t, $t->base['position']);

                return;
            case 5006: if ($u === $t) {
                $this->move($u, 'back');

                return;
            } $this->move($t, 'front');
                $this->statusChanges($s, $t);

                return;
            case 5060: $this->statusChanges(['DownDEF' => 30, 'DownMDEF' => 30], $t);
                $this->statusChanges(['UpDEF' => 30, 'UpMDEF' => 30], $u);

                return;
            case 5022: if ($u !== $t) {
                $this->resource($t, 'hp', $this->healing($s, $u), 'fortune');
                $this->statusChanges($s, $t);
            }

                return;
            case 5803: $choices = [1018, 1019, 1020, 1021, 5002];
                $this->summon($u, $choices[$this->rng->integer(0, 4)], false, false);

                return;
        }
        if (! empty($s['MagicCircleAdd'])) {
            $this->circles[$u->team] = min(5, $this->circles[$u->team] + (int) $s['MagicCircleAdd']);
            $this->event('MagicCirclesChanged', ['team' => $u->team, 'value' => $this->circles[$u->team]]);
        }
        if (! empty($s['MagicCircleDeleteEnemy'])) {
            $enemy = 1 - $u->team;
            $n = (int) $s['MagicCircleDeleteEnemy'];
            if ($this->circles[$enemy] >= $n) {
                $this->circles[$enemy] -= $n;
            }
            $this->event('MagicCirclesChanged', ['team' => $enemy, 'value' => $this->circles[$enemy]]);
        }
        foreach (['HpRegen', 'SpRegen'] as $key) {
            if (! empty($s[$key])) {
                $t->v['SPECIAL'][$key] = ($t->v['SPECIAL'][$key] ?? 0) + (int) $s[$key];
                $this->event('SpecialChanged', ['target' => $t->id, 'special' => $key, 'value' => $t->v['SPECIAL'][$key]]);
            }
        }
        if (($s['priority'] ?? '') === 'Charge' && $t->casting === null) {
            return;
        }
        if (! empty($s['summon'])) {
            foreach ((array) $s['summon'] as $no) {
                $this->summon($u, (int) $no, true, ! empty($s['quick']));
            }

            return;
        }
        if (! empty($s['CurePoison']) && $t->v['state'] === 2) {
            $this->normal($t);
        }
        if (! empty($s['pow'])) {
            if (! empty($s['support'])) {
                $this->resource($t, 'hp', $this->healing($s, $u), 'healing');
                $this->statusChanges($s, $t);
            } else {
                $this->resource($t, 'hp', -$this->basicDamage($s, $u, $t, 1, ! empty($s['pierce'])), 'attack');
            }
        }
        if (! empty($s['SpRecoveryRate'])) {
            $this->resource($t, 'sp', (int) ceil(sqrt($t->v['maxsp']) * $s['SpRecoveryRate']), 'sp_recovery');
        }
        if (! empty($s['poison'])) {
            $this->poison($t, (float) $s['poison']);
        }
        if (! empty($s['knockback'])) {
            $this->move($t, 'back');
        }
        // The effective reference applies stat changes twice for powered support skills.
        $this->statusChanges($s, $t);
        if (! empty($s['move'])) {
            $this->move($t, $s['move']);
        }
        if (! empty($s['delay'])) {
            $this->delay($t, (float) $s['delay']);
        }
    }

    private function basicDamage(array $s, Combatant $u, Combatant $t, float $multiply = 1, bool $pierce = false): int
    {
        $type = (int) ($s['type'] ?? 0) === 0 ? 0 : 1;
        $stat = $type === 0 ? (($s['inf'] ?? '') === 'dex' ? 'dex' : 'str') : 'int';
        $power = (float) ($s['pow'] ?? 0) / 100;
        $n = (sqrt($u->v[$stat]) * 10 + ($u->v['atk'][$type] ?? 0)) * $power * $multiply;
        if (! empty($t->v['SPECIAL']['Barrier'])) {
            $t->v['SPECIAL']['Barrier'] = false;
            $n = 0;
            $this->event('BarrierConsumed', ['target' => $t->id]);
        }
        $minimum = $n * 0.1;
        if (! $pierce) {
            $n = $n * (1 - ($t->v['def'][$type * 2] ?? 0) / 100) - ($t->v['def'][$type * 2 + 1] ?? 0);
        }
        $n += ($u->v['SPECIAL']['Pierce'][$type] ?? 0) * $power;

        return (int) ceil(max($minimum, $n));
    }

    private function healing(array $s, Combatant $u): int
    {
        return (int) ceil((sqrt($u->v['int']) * 10 + ($u->v['atk'][1] ?? 0)) * (float) ($s['pow'] ?? 0) / 100);
    }

    private function resource(Combatant $u, string $resource, int $change, string $reason, bool $nonlethal = false): void
    {
        $before = $u->v[$resource];
        $floor = $resource === 'hp' && $nonlethal && $u->alive() ? 1 : 0;
        $u->v[$resource] = max($floor, min($u->v['max'.$resource], $before + $change));
        $actual = $u->v[$resource] - $before;
        if ($actual === 0 && $change === 0) {
            return;
        }
        $this->event($actual < 0 ? 'DamageApplied' : 'ResourceRecovered', ['target' => $u->id, 'resource' => $resource, 'amount' => abs($actual), 'requested' => abs($change), 'before' => $before, 'after' => $u->v[$resource], 'reason' => $reason]);
        if ($resource === 'hp' && $actual < 0 && $this->actingTeam !== null && $u->team !== $this->actingTeam) {
            $this->damage[$this->actingTeam] -= $actual;
        }
    }

    private function poisonDamage(Combatant $u, float $multiply = 1): void
    {
        if ($u->v['state'] !== 2) {
            return;
        }
        if (! empty($u->v['boss'])) {
            $n = min(200, round($u->v['hp'] * 0.01) * $this->rng->integer(50, 150) / 100);
        } else {
            $n = round($u->v['maxhp'] * 0.1) + ceil($u->v['level'] / 2);
        }
        $this->resource($u, 'hp', -(int) round(max(0, $n * $multiply)), 'poison', true);
    }

    private function poison(Combatant $u, float $chance): void
    {
        if ($u->v['state'] === 2 || ! $u->alive()) {
            return;
        }
        $resistance = (float) ($u->v['SPECIAL']['PoisonResist'] ?? 0);
        // Without resistance the reference always poisons, even when chance is below 100.
        if ($resistance && $this->rng->integer(0, 99) >= $chance * (1 - $resistance / 100)) {
            $this->event('EffectResisted', ['target' => $u->id, 'effect' => 'poison']);

            return;
        }
        $u->v['state'] = 2;
        $this->event('StatusChanged', ['target' => $u->id, 'state' => 2]);
    }

    private function normal(Combatant $u): bool
    {
        if (! $u->alive() && ($u->v['summon'] || ! empty($u->v['boss']))) {
            return false;
        }
        $u->v['state'] = 0;
        $this->event('StatusChanged', ['target' => $u->id, 'state' => 0]);

        return true;
    }

    private function move(Combatant $u, string $position): void
    {
        if (! in_array($position, ['front', 'back'], true)) {
            throw new \InvalidArgumentException('Unknown position');
        }
        if ($u->v['position'] === $position) {
            return;
        }
        $u->v['position'] = $position;
        $this->event('PositionChanged', ['target' => $u->id, 'position' => $position]);
    }

    private function delay(Combatant $u, float $amount): void
    {
        if (! empty($u->v['boss'])) {
            $amount = round($amount / 3);
        }
        $u->progress -= $amount;
        $this->event('DelayChanged', ['target' => $u->id, 'progress' => $u->progress]);
    }

    private function accelerate(Combatant $u, float $percent): void
    {
        $u->progress += (100 - $u->progress) * $percent / 100;
        $this->event('DelayChanged', ['target' => $u->id, 'progress' => $u->progress]);
    }

    private function statusChanges(array $s, Combatant $u): void
    {
        foreach (['Plus', 'Up', 'Down'] as $operation) {
            foreach (['MAXHP', 'MAXSP', 'STR', 'INT', 'DEX', 'SPD', 'LUK', 'ATK', 'MATK', 'DEF', 'MDEF'] as $stat) {
                $key = $operation.$stat;
                if (empty($s[$key])) {
                    continue;
                }
                $n = (float) $s[$key];
                if ($operation === 'Plus' && ! in_array($stat, ['STR', 'INT', 'DEX', 'SPD', 'LUK'], true)) {
                    continue;
                }
                if ($operation !== 'Plus' && $stat === 'LUK') {
                    continue;
                }
                if (! empty($u->v['boss']) && $operation === 'Down' && in_array($stat, ['MAXHP', 'MAXSP', 'ATK', 'MATK', 'DEF', 'MDEF'], true)) {
                    $n = in_array($stat, ['MAXHP', 'MAXSP'], true) ? $n / 2 : round($n / 2);
                }
                $field = strtolower($stat);
                $index = null;
                if (in_array($stat, ['ATK', 'MATK'], true)) {
                    $field = 'atk';
                    $index = $stat === 'ATK' ? 0 : 1;
                }
                if (in_array($stat, ['DEF', 'MDEF'], true)) {
                    $field = 'def';
                    $index = $stat === 'DEF' ? 0 : 2;
                }
                $old = $index === null ? $u->v[$field] : ($u->v[$field][$index] ?? 0);
                if ($operation === 'Plus') {
                    $new = $old + $n;
                } elseif ($operation === 'Up' && $field === 'def') {
                    $new = $old + floor((100 - $old) * $n / 100);
                } else {
                    $new = round($old * (1 + ($operation === 'Up' ? 1 : -1) * $n / 100));
                }
                if ($operation === 'Up' && in_array($stat, ['STR', 'INT', 'DEX', 'SPD'], true)) {
                    $new = min($new, round(($u->base['baseStats'][$field] ?? $u->base[$field]) * 25));
                }
                $new = max($stat === 'MAXHP' ? 1 : 0, min(1000000000, $new));
                if ($index === null) {
                    $u->v[$field] = (int) $new;
                } else {
                    $u->v[$field][$index] = (int) $new;
                }
                if ($stat === 'MAXHP') {
                    $u->v['hp'] = min($u->v['hp'], $u->v['maxhp']);
                }
                if ($stat === 'MAXSP') {
                    $u->v['sp'] = min($u->v['sp'], $u->v['maxsp']);
                }
                $this->event('StatChanged', ['target' => $u->id, 'stat' => $stat, 'before' => $old, 'after' => $new, 'reason' => $key]);
            }
        }
    }

    private function summon(Combatant $owner, int $prototype, bool $scale, bool $quick): void
    {
        if (! isset($this->summons[$prototype])) {
            throw new \InvalidArgumentException("Missing summon prototype $prototype");
        }
        if (count($this->ids) >= $this->snapshot->maxUnits) {
            $this->event('SummonBlocked', ['actor' => $owner->id, 'reason' => 'unit_safety_bound']);

            return;
        }
        $data = $this->summons[$prototype];
        if (empty($data['position'])) {
            $data['position'] = $this->rng->integer(0, 1) ? 'front' : 'back';
        }
        if ($scale) {
            $strength = (1 + (sqrt($owner->v['dex']) * 5 + $owner->v['luk']) / 250) * (100 + ($owner->v['SPECIAL']['Summon'] ?? 0)) / 100;
            foreach (['maxhp', 'hp', 'maxsp', 'sp', 'str', 'int', 'dex', 'spd', 'luk'] as $key) {
                $data[$key] = (int) round(($data[$key] ?? 0) * $strength);
            }
            foreach ([0, 1] as $key) {
                $data['atk'][$key] = (int) round(($data['atk'][$key] ?? 0) * $strength);
            }
        }
        $data['summon'] = true;
        $data['monster'] = true;
        do {
            $id = 'summon:'.$owner->id.':'.++$this->summonSerial;
        } while (isset($this->ids[$id]));
        $this->ids[$id] = true;
        $data = SnapshotFactory::withMonsterImage($data, $id, $this->rng);
        $unit = new Combatant($id, $owner->team, $data);
        $this->validateTactics($unit);
        if ($quick) {
            $unit->progress = 100.1;
        }
        $this->teams[$owner->team][] = $unit;
        $this->event('Summoned', ['actor' => $owner->id, 'target' => $id, 'prototype' => $prototype, 'unit' => $unit->export()]);
    }
}
