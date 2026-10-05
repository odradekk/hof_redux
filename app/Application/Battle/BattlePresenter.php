<?php

declare(strict_types=1);

namespace App\Application\Battle;

use App\Domain\Content\ContentCatalog;

/** Converts stored engine events to escaped player-facing prose, never diagnostic payloads. */
final class BattlePresenter
{
    public function __construct(private ContentCatalog $catalog) {}

    public function present(array $report): array
    {
        $names = [];
        foreach (array_merge($report['initial_teams'] ?? [], $report['teams'] ?? []) as $team) {
            foreach ($team as $unit) {
                $names[$unit['id']] = $unit['name'];
            }
        }
        foreach ($report['events'] ?? [] as $event) {
            if (($event['type'] ?? '') === 'Summoned' && isset($event['unit']['name'])) {
                $names[$event['target']] = $event['unit']['name'];
            }
        }
        $lines = [];
        foreach ($report['events'] ?? [] as $event) {
            $actor = $names[$event['actor'] ?? ''] ?? '角色';
            $target = $names[$event['target'] ?? ''] ?? '目标';
            $skill = isset($event['skill']) && $this->catalog->has('skills', $event['skill']) ? $this->catalog->get('skills', $event['skill'])['name'] : '技能';
            $amount = max(0, (int) ($event['amount'] ?? 0));
            $resource = ($event['resource'] ?? 'hp') === 'sp' ? 'SP' : 'HP';
            $tone = 'normal';
            $text = match ($event['type'] ?? '') {
                'BattleStarted' => '战斗开始！',
                'SkillUsed' => $actor.' 使用「'.$skill.'」。',
                'CastStarted' => $actor.' 开始咏唱「'.$skill.'」。',
                'CastInterrupted' => $actor.' 的咏唱被打断。',
                'Guarded' => $actor.' 保护了 '.$target.'！',
                'ActorDied' => $actor.' 被打倒了。',
                'ActorLeveled' => $actor.' 升至 '.(int) ($event['level'] ?? 1).' 级！',
                'Summoned' => $actor.' 召唤了 '.$target.'。',
                'SummonBlocked' => $actor.' 无法继续召唤。',
                'AppearanceChanged' => $target.' 完成了变身！',
                'BarrierConsumed' => $target.' 的屏障抵挡了攻击。',
                'EffectResisted' => $target.' 抵抗了效果。',
                'SkillFailed' => $actor.' 的「'.$skill.'」未能生效'.match ($event['reason'] ?? '') {
                    'sp' => '：SP 不足。', 'no_target' => '：没有合适的目标。', 'weapon' => '：武器不适用。', default => '。'
                },
                'PositionChanged' => $target.' 移动到'.(($event['position'] ?? '') === 'front' ? '前排' : '后排').'。',
                'StatusChanged' => $target.match ((int) ($event['state'] ?? 0)) {
                    1 => ' 被打倒了。', 2 => ' 中毒了！', default => ' 恢复了正常状态。'
                },
                'StatChanged' => $target.' 的'.$this->statName((string) ($event['stat'] ?? '')).'发生了变化。',
                'SpecialChanged' => $target.' 获得了'.$this->specialName((string) ($event['special'] ?? '')).'效果。',
                'MagicCirclesChanged' => ($report['names'][$event['team'] ?? 0] ?? '队伍').' 的魔法阵发生了变化。',
                default => null,
            };
            if (in_array($event['type'] ?? '', ['DamageApplied', 'ResourceRecovered', 'ResourceChanged'], true)) {
                if (($event['reason'] ?? '') === 'skill_cost' || $amount === 0) {
                    continue;
                }
                if ($event['type'] === 'DamageApplied') {
                    $tone = 'damage';
                    $text = $target.(($event['reason'] ?? '') === 'poison' ? ' 因中毒' : '').($resource === 'HP' ? ' 受到 '.$amount.' 点伤害。' : ' 失去 '.$amount.' 点 SP。');
                } else {
                    $tone = 'recovery';
                    $text = $target.(in_array($event['reason'] ?? '', ['resurrection', 'zombie_revival'], true) ? ' 重新站了起来，并恢复了 ' : ' 恢复了 ').$amount.' 点'.$resource.'。';
                }
            }
            if ($text !== null) {
                $lines[] = ['text' => $text, 'tone' => $tone];
            }
        }
        $items = [];
        foreach ($report['settlement']['items'] ?? [] as $id => $quantity) {
            $items[] = ['name' => $this->catalog->has('items', (string) $id) ? $this->catalog->get('items', (string) $id)['name'] : '道具', 'quantity' => (int) $quantity];
        }

        return ['lines' => $lines, 'items' => $items];
    }

    private function statName(string $stat): string
    {
        return ['STR' => '力量', 'INT' => '智力', 'DEX' => '技巧', 'SPD' => '速度', 'LUK' => '幸运', 'MAXHP' => '最大生命', 'MAXSP' => '最大精神', 'ATK' => '攻击', 'MATK' => '魔法攻击', 'DEF' => '防御', 'MDEF' => '魔法防御'][$stat] ?? '能力';
    }

    private function specialName(string $special): string
    {
        return ['Barrier' => '屏障', 'PoisonResist' => '毒抗性', 'HpRegen' => '生命恢复', 'SpRegen' => '精神恢复', 'Metamo' => '变身', 'Summon' => '召唤强化'][$special] ?? '特殊';
    }
}
