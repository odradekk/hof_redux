<?php

declare(strict_types=1);

namespace App\Application\Battle;

use App\Domain\Content\ContentCatalog;

/** Rebuilds public, value-only battle scenes and prose from the ordered event log. */
final class BattlePresenter
{
    public const EVENTS = [
        'BattleStarted' => 'narrative', 'SkillUsed' => 'narrative', 'CastStarted' => 'narrative',
        'CastInterrupted' => 'narrative', 'Guarded' => 'narrative', 'ActorDied' => 'narrative',
        'ActorLeveled' => 'narrative', 'Summoned' => 'narrative', 'SummonBlocked' => 'narrative',
        'AppearanceChanged' => 'narrative', 'BarrierConsumed' => 'narrative', 'EffectResisted' => 'narrative',
        'SkillFailed' => 'narrative', 'PositionChanged' => 'narrative', 'StatusChanged' => 'narrative',
        'StatChanged' => 'narrative', 'SpecialChanged' => 'narrative', 'MagicCirclesChanged' => 'narrative',
        'ActionSkipped' => 'narrative', 'DamageApplied' => 'resourceLine',
        'ResourceRecovered' => 'resourceLine', 'ResourceChanged' => 'resourceLine',
    ];

    public const HIDDEN = ['ActorSelected', 'TargetSelected', 'DelayChanged', 'BattleFinished'];

    private BattleStage $stage;

    public function __construct(private ContentCatalog $catalog, ?BattleStage $stage = null)
    {
        $this->stage = $stage ?? new BattleStage($catalog);
    }

    public function present(array $report): array
    {
        $teams = $this->normalize($report['initial_teams'] ?? $report['teams'] ?? [[], []]);
        $names = $sides = $bosses = [];
        foreach (array_merge($report['initial_teams'] ?? [], $report['teams'] ?? []) as $team) {
            foreach ($team as $unit) {
                $names[(string) $unit['id']] = (string) $unit['name'];
                if (($unit['boss'] ?? false) || ($unit['id'] ?? '') === 'boss') {
                    $bosses[(string) $unit['id']] = true;
                }
            }
        }
        foreach ($teams as $team => $units) {
            foreach ($units as $unit) {
                $sides[$unit['id']] = $team;
                $teams[$team][$unit['id']]['boss'] = isset($bosses[$unit['id']]);
            }
        }
        $events = $report['events'] ?? [];
        foreach ($events as $index => &$event) {
            $event['_order'] = $index;
        }
        unset($event);
        usort($events, static fn (array $a, array $b): int => [($a['sequence'] ?? $a['_order']), $a['_order']] <=> [($b['sequence'] ?? $b['_order']), $b['_order']]);
        foreach ($events as $event) {
            if (($event['type'] ?? '') === 'Summoned' && isset($event['unit']['name'])) {
                $id = (string) $event['target'];
                $names[$id] = (string) $event['unit']['name'];
                $sides[$id] = (int) ($event['unit']['team'] ?? $sides[$event['actor']] ?? 0);
                if ($event['unit']['boss'] ?? false) {
                    $bosses[$id] = true;
                }
            }
        }
        $circles = $report['initial_magic_circles'] ?? [0, 0];
        $header = $this->summaries($teams, $report);
        $segments = [$this->segment($teams, $circles, $report, 0, 0)];
        $lines = $levelups = [];
        $selected = 0;
        $countedActions = 0;
        $drops = [];
        foreach ($report['rewards'] ?? [] as $batches) {
            foreach ($batches as $batch) {
                foreach ($batch['items'] ?? [] as $id => $quantity) {
                    $item = $this->catalog->has('items', (string) $id) ? $this->catalog->get('items', (string) $id) : [];
                    $drops[(int) $batch['action']][] = $this->line('获得战利品：'.($item['name'] ?? '道具').' × '.number_format((int) $quantity).'。', 'support') + ['icon' => $this->icon($item['img'] ?? '')];
                }
            }
        }
        $segmentIndex = 0;
        $actionIndex = null;
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            if (! isset(self::EVENTS[$type]) && ! in_array($type, self::HIDDEN, true)) {
                throw new \UnexpectedValueException('Unregistered battle event: '.$type);
            }
            if ($type === 'ActorSelected') {
                $this->appendDrops($segments[$segmentIndex], $actionIndex, $countedActions, $drops, $lines);
                $countedActions++;
                if ($selected > 0 && $selected % 10 === 0) {
                    $segments[] = $this->segment($teams, $circles, $report, ++$segmentIndex, $selected);
                }
                $selected++;
                $actor = (string) ($event['actor'] ?? '');
                $segments[$segmentIndex]['actions'][] = ['side' => ($sides[$actor] ?? 0) === 1 ? 'foe' : 'ally', 'actor' => $names[$actor] ?? '角色', 'skill' => null, 'lines' => []];
                $actionIndex = count($segments[$segmentIndex]['actions']) - 1;
            }
            if (isset(self::EVENTS[$type])) {
                $line = $this->{self::EVENTS[$type]}($event, $names, $bosses, $report);
                if ($line !== null) {
                    $lines[] = $line;
                    if ($type === 'ActorLeveled') {
                        $levelups[] = $line['text'];
                    }
                    if ($type !== 'BattleStarted') {
                        if ($actionIndex === null) {
                            $actor = (string) ($event['actor'] ?? $event['target'] ?? '');
                            $segments[$segmentIndex]['actions'][] = ['side' => ($sides[$actor] ?? 0) === 1 ? 'foe' : 'ally', 'actor' => $names[$actor] ?? '角色', 'skill' => null, 'lines' => []];
                            $actionIndex = count($segments[$segmentIndex]['actions']) - 1;
                        }
                        if (in_array($type, ['SkillUsed', 'CastStarted', 'SkillFailed'], true)) {
                            $segments[$segmentIndex]['actions'][$actionIndex]['skill'] = $this->skill($event['skill'] ?? null);
                        }
                        if ($type !== 'SkillUsed') {
                            $segments[$segmentIndex]['actions'][$actionIndex]['lines'][] = $line;
                        }
                    }
                }
            }
            $this->apply($teams, $circles, $event, $sides);
            if ($type === 'CastStarted') {
                $countedActions--;
            }
        }
        $this->appendDrops($segments[$segmentIndex], $actionIndex, $countedActions, $drops, $lines);
        $items = [];
        foreach ($report['settlement']['items'] ?? [] as $id => $quantity) {
            $item = $this->catalog->has('items', (string) $id) ? $this->catalog->get('items', (string) $id) : [];
            $items[] = ['name' => $item['name'] ?? '道具', 'quantity' => (int) $quantity, 'icon' => $this->icon($item['img'] ?? '')];
        }
        $winner = $report['winner'] ?? null;
        $winnerSide = $winner === null ? null : ($winner === 0 ? 'ally' : 'foe');
        $finalTeams = isset($report['teams']) ? $this->normalize($report['teams']) : $teams;
        $summary = $this->summaries($finalTeams, $report);
        foreach (['foe' => 1, 'ally' => 0] as $side => $team) {
            $summary[$side]['damage'] = ($report['mode'] ?? '') === 'boss' ? '????' : (int) ($report['damage'][$team] ?? 0);
            $summary[$side]['experience'] = $team === 0 ? array_sum($report['settlement']['experience'] ?? []) : 0;
            $summary[$side]['money'] = $team === 0 ? (int) ($report['settlement']['money'] ?? 0) : 0;
        }

        return [
            'title' => $header['foe']['name'].' vs '.$header['ally']['name'],
            'mode' => ['pve' => '普通战', 'boss' => 'BOSS 战', 'pvp' => '竞技场', 'simulation' => '模拟战'][$report['mode'] ?? 'pve'] ?? '战斗',
            'limited' => in_array($report['reason'] ?? '', ['action_limit', 'step_limit', 'survivor_limit'], true),
            'header' => $header, 'segments' => $segments, 'lines' => $lines, 'items' => $items,
            'result' => ['winner_side' => $winnerSide, 'label' => $winner === null ? '平局' : ($report['names'][$winner] ?? '队伍').' 胜利!', 'summary' => $summary, 'items' => $items, 'levelups' => $levelups],
        ];
    }

    private function appendDrops(array &$segment, ?int $index, int $action, array &$drops, array &$lines): void
    {
        if ($index === null || ! isset($drops[$action])) {
            return;
        }
        foreach ($drops[$action] as $line) {
            $segment['actions'][$index]['lines'][] = $line;
            $lines[] = $line;
        }
        unset($drops[$action]);
    }

    /** A public list entry deliberately excludes events, resources and hidden engine data. */
    public function summary(array $report): array
    {
        $teams = $this->normalize($report['initial_teams'] ?? $report['teams'] ?? [[], []]);
        $participants = [];
        foreach ([0, 1] as $team) {
            $units = array_filter($teams[$team], static fn (array $unit): bool => ! $unit['summon']);
            $participants[] = [
                'name' => $report['names'][$team] ?? ($team === 0 ? '队伍' : '敌人'),
                'count' => count($units),
                'average_level' => count($units) ? round(array_sum(array_column($units, 'level')) / count($units), 1) : 0,
                'tone' => ($report['winner'] ?? null) === null ? '' : ((int) $report['winner'] === $team ? 'recover' : 'dmg'),
            ];
        }

        return ['actions' => (int) ($report['actions'] ?? 0), 'result' => ($report['winner'] ?? null) === null ? '平' : ((int) $report['winner'] === 0 ? '胜' : '败'), 'participants' => $participants];
    }

    private function normalize(array $teams): array
    {
        $normalized = [[], []];
        foreach ([0, 1] as $team) {
            foreach ($teams[$team] ?? [] as $unit) {
                $unit += ['name' => '角色', 'maxhp' => 0, 'maxsp' => 0, 'position' => 'front', 'state' => 0, 'level' => 1, 'summon' => false, 'casting' => null];
                $unit['id'] = (string) ($unit['id'] ?? $team.':'.count($normalized[$team]));
                $unit['hp'] ??= $unit['maxhp'];
                $unit['sp'] ??= $unit['maxsp'];
                $unit['boss'] = ($unit['boss'] ?? false) || $unit['id'] === 'boss';
                $normalized[$team][$unit['id']] = $unit;
            }
        }

        return $normalized;
    }

    private function segment(array $teams, array $circles, array $report, int $index, int $action): array
    {
        $units = ['foe' => ['back' => [], 'front' => []], 'ally' => ['front' => [], 'back' => []]];
        $alive = [0, 0];
        foreach ([1 => 'foe', 0 => 'ally'] as $team => $side) {
            foreach ($teams[$team] as $unit) {
                if ($unit['summon'] && (int) $unit['state'] === 1) {
                    continue;
                }
                $alive[$team] += (int) ((int) $unit['state'] !== 1);
                $hidden = (bool) $unit['boss'];
                $casting = $unit['casting'] !== null ? $this->skill($unit['casting']) : null;
                $units[$side][$unit['position']][] = [
                    'id' => $unit['id'], 'name' => $unit['name'], 'state' => (int) $unit['state'],
                    'hp' => $hidden ? '????' : (int) $unit['hp'], 'maxhp' => $hidden ? '????' : (int) $unit['maxhp'],
                    'sp' => $hidden ? '????' : (int) $unit['sp'], 'maxsp' => $hidden ? '????' : (int) $unit['maxsp'],
                    'casting' => $casting ? ($casting['type'] === 0 ? '蓄力' : '咏唱') : null,
                ];
            }
        }
        $label = '第 '.($index + 1).' 段战况：左侧'.($report['names'][1] ?? '敌人').' '.$alive[1].' 名存活，右侧'.($report['names'][0] ?? '队伍').' '.$alive[0].' 名存活';

        return ['index' => $index, 'action' => $action, 'label' => $label, 'stage' => $this->stage->present($teams, (string) ($report['background'] ?? 'grass'), $circles), 'units' => $units, 'actions' => []];
    }

    private function summaries(array $teams, array $report): array
    {
        $summary = [];
        foreach ([1 => 'foe', 0 => 'ally'] as $team => $side) {
            $units = array_filter($teams[$team], static fn (array $unit): bool => ! ($unit['summon'] && (int) $unit['state'] === 1));
            $hidden = count(array_filter($units, static fn (array $unit): bool => (bool) $unit['boss'])) > 0 || ($team === 1 && ($report['mode'] ?? '') === 'boss');
            $levels = array_sum(array_column($units, 'level'));
            $summary[$side] = [
                'name' => $report['names'][$team] ?? ($team === 0 ? '队伍' : '敌人'),
                'total_level' => $levels, 'average_level' => count($units) ? round($levels / count($units), 1) : 0,
                'hp' => $hidden ? '????' : (int) array_sum(array_column($units, 'hp')),
                'maxhp' => $hidden ? '????' : (int) array_sum(array_column($units, 'maxhp')),
                'alive' => count(array_filter($units, static fn (array $unit): bool => (int) $unit['state'] !== 1)),
                'total' => count($units),
            ];
        }

        return $summary;
    }

    private function apply(array &$teams, array &$circles, array $event, array $sides): void
    {
        $type = $event['type'];
        if ($type === 'MagicCirclesChanged') {
            $circles[(int) $event['team']] = (int) $event['value'];

            return;
        }
        if ($type === 'Summoned' && isset($event['unit'])) {
            $id = (string) $event['target'];
            $team = $sides[$id] ?? 0;
            $teams[$team][$id] = $this->normalize([$team => [$event['unit'] + ['id' => $id]]])[$team][$id];

            return;
        }
        $id = (string) ($event['target'] ?? $event['actor'] ?? '');
        $team = $sides[$id] ?? null;
        if ($team === null || ! isset($teams[$team][$id])) {
            return;
        }
        $unit = &$teams[$team][$id];
        switch ($type) {
            case 'DamageApplied': case 'ResourceRecovered': case 'ResourceChanged':
                $resource = ($event['resource'] ?? 'hp') === 'sp' ? 'sp' : 'hp';
                if (isset($event['after'])) {
                    $unit[$resource] = (int) $event['after'];
                }
                break;
            case 'StatChanged':
                $field = strtolower((string) ($event['stat'] ?? ''));
                if (isset($event['after']) && in_array($field, ['maxhp', 'maxsp', 'str', 'int', 'dex', 'spd', 'luk'], true)) {
                    $unit[$field] = (int) $event['after'];
                    // The engine clamps current resources without a second resource event.
                    if (in_array($field, ['maxhp', 'maxsp'], true)) {
                        $resource = substr($field, 3);
                        $unit[$resource] = min($unit[$resource], $unit[$field]);
                    }
                }
                break;
            case 'StatusChanged': $unit['state'] = (int) $event['state'];
                break;
            case 'PositionChanged': $unit['position'] = $event['position'];
                break;
            case 'AppearanceChanged': $unit['img'] = $event['image'];
                break;
            case 'CastStarted': $unit['casting'] = (int) $event['skill'];
                break;
            case 'CastInterrupted': case 'SkillUsed': case 'SkillFailed': $unit['casting'] = null;
                break;
            case 'ActorDied': $unit['state'] = 1;
                $unit['hp'] = 0;
                $unit['casting'] = null;
                break;
            case 'ActorLeveled': $unit['level'] = (int) $event['level'];
                break;
            case 'SpecialChanged': $unit['SPECIAL'][$event['special']] = $event['value'];
                break;
            case 'BarrierConsumed': $unit['SPECIAL']['Barrier'] = false;
                break;
        }
    }

    private function narrative(array $event, array $names, array $bosses, array $report): array
    {
        $actor = $names[$event['actor'] ?? ''] ?? '角色';
        $target = $names[$event['target'] ?? ''] ?? '目标';
        $skill = $this->skill($event['skill'] ?? null);
        $tone = match ($event['type']) {
            'ActorDied' => 'damage', 'ActorLeveled' => 'levelup', 'CastStarted', 'CastInterrupted' => 'charge',
            'StatusChanged' => match ((int) ($event['state'] ?? 0)) {
                1 => 'damage', 2 => 'poison', default => 'support'
            },
            'StatChanged', 'SpecialChanged', 'MagicCirclesChanged', 'Guarded', 'BarrierConsumed', 'Summoned' => 'support',
            default => 'normal',
        };
        $text = match ($event['type']) {
            'BattleStarted' => '战斗开始！',
            'SkillUsed' => $actor.' 使用「'.$skill['name'].'」。',
            'CastStarted' => $actor.' 开始'.($skill['type'] === 0 ? '蓄力' : '咏唱').'「'.$skill['name'].'」。',
            'CastInterrupted' => $actor.' 的蓄力或咏唱被打断。',
            'Guarded' => $actor.' 保护了 '.$target.'！',
            'ActorDied' => $actor.' 被打倒了。',
            'ActorLeveled' => $actor.' 升至 '.(int) ($event['level'] ?? 1).' 级！',
            'Summoned' => $actor.' 召唤了 '.$target.'。',
            'SummonBlocked' => $actor.' 无法继续召唤。',
            'AppearanceChanged' => $target.' 完成了变身！',
            'BarrierConsumed' => $target.' 的屏障抵挡了基础伤害。',
            'EffectResisted' => $target.' 抵抗了效果。',
            'SkillFailed' => $actor.' 的「'.$skill['name'].'」未能生效'.match ($event['reason'] ?? '') {
                'sp' => '：魔力不足。', 'no_target' => '：没有合适的目标。', 'weapon' => '：武器不适用。',
                'magic_circles' => '：魔法阵不足。', 'metamorphosis_condition' => '：尚未满足变身条件。', default => '。',
            },
            'PositionChanged' => $target.' 移动到'.(($event['position'] ?? '') === 'front' ? '前排' : '后排').'。',
            'StatusChanged' => $target.match ((int) ($event['state'] ?? 0)) {
                1 => ' 被打倒了。', 2 => ' 中毒了！', default => ' 恢复了正常状态。'
            },
            'StatChanged' => $target.' 的'.$this->statName((string) ($event['stat'] ?? '')).'发生了变化。',
            'SpecialChanged' => $target.' 获得了'.$this->specialName((string) ($event['special'] ?? '')).'效果。',
            'MagicCirclesChanged' => ($report['names'][$event['team'] ?? 0] ?? '队伍').' 的魔法阵变为 '.(int) ($event['value'] ?? 0).' 个。',
            'ActionSkipped' => $actor.' 陷入沉思结果忘了行动。(无更多行动模式)',
        };
        $change = $event['type'] === 'StatChanged' ? $this->change($event, $bosses) : null;

        return $this->line($text, $tone, $change);
    }

    private function resourceLine(array $event, array $names, array $bosses, array $report): ?array
    {
        $amount = max(0, (int) ($event['amount'] ?? 0));
        if (($event['reason'] ?? '') === 'skill_cost' || $amount === 0) {
            return null;
        }
        $target = $names[$event['target'] ?? ''] ?? '目标';
        $resource = ($event['resource'] ?? 'hp') === 'sp' ? '魔力' : '生命';
        $damage = $event['type'] === 'DamageApplied' || ($event['type'] === 'ResourceChanged' && isset($event['before'], $event['after']) && $event['after'] < $event['before']);
        $text = $damage
            ? $target.(($event['reason'] ?? '') === 'poison' ? ' 因中毒' : '').($resource === '生命' ? ' 受到 '.number_format($amount).' 点伤害。' : ' 失去 '.number_format($amount).' 点魔力。')
            : $target.(in_array($event['reason'] ?? '', ['resurrection', 'zombie_revival'], true) ? ' 重新站了起来，并恢复了 ' : ' 恢复了 ').number_format($amount).' 点'.$resource.'。';

        return $this->line($text, $damage ? 'damage' : 'recovery', $this->change($event, $bosses));
    }

    private function change(array $event, array $bosses): ?string
    {
        if (! isset($event['before'], $event['after'])) {
            return null;
        }
        if (isset($bosses[(string) ($event['target'] ?? '')])) {
            return '(??? > ???)';
        }

        return '('.number_format((int) $event['before']).' > '.number_format((int) $event['after']).')';
    }

    private function line(string $text, string $tone, ?string $change = null): array
    {
        return ['text' => $text, 'tone' => $tone, 'class' => ['damage' => 'dmg', 'recovery' => 'recover', 'poison' => 'spdmg', 'charge' => 'charge', 'support' => 'support', 'levelup' => 'levelup', 'normal' => ''][$tone], 'change' => $change];
    }

    private function skill(int|string|null $id): array
    {
        $skill = $id !== null && $this->catalog->has('skills', $id) ? $this->catalog->get('skills', $id) : [];

        return ['name' => $skill['name'] ?? '技能', 'icon' => $this->icon($skill['img'] ?? ''), 'type' => (int) ($skill['type'] ?? 0)];
    }

    private function icon(string $filename): ?string
    {
        return $filename !== '' && basename($filename) === $filename ? 'image/icon/'.$filename : null;
    }

    private function statName(string $stat): string
    {
        return ['STR' => '力量', 'INT' => '智慧', 'DEX' => '敏捷', 'SPD' => '速度', 'LUK' => '幸运', 'MAXHP' => '最大生命', 'MAXSP' => '最大魔力', 'ATK' => '物理攻击', 'MATK' => '魔法攻击', 'DEF' => '物理防御', 'MDEF' => '魔法防御'][$stat] ?? '能力';
    }

    private function specialName(string $special): string
    {
        return ['Barrier' => '屏障', 'PoisonResist' => '毒抗性', 'HpRegen' => '生命恢复', 'SpRegen' => '魔力恢复', 'Metamo' => '变身', 'Summon' => '召唤强化'][$special] ?? '特殊';
    }
}
