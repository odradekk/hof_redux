<?php

declare(strict_types=1);

namespace App\Application\World;

use App\Domain\Content\ContentCatalog;

/**
 * Chinese wording for content fields, following the legacy ShowItemDetail()/ShowSkillDetail()
 * one-line style. Every field a definition can carry is either rendered here or listed in
 * IGNORED_* with a reason, so the public data pages never drop a rule silently.
 */
final class GameText
{
    /** Attribute keys in display order; labels come from the hof.stats glossary. */
    public const STAT_KEYS = ['str', 'int', 'dex', 'spd', 'luk'];

    public const TARGET_SIDE = ['enemy' => ['dmg', '敌方'], 'friend' => ['recover', '友方'], 'self' => ['support', '自己'], 'all' => ['charge', '战场全体']];

    public const TARGET_STYLE = ['individual' => ['recover', '单体'], 'multi' => ['spdmg', '随机多次'], 'all' => ['charge', '全体']];

    public const PRIORITY = [
        'Back' => '优先选择后卫', 'Dead' => '以倒下的角色为对象', '死亡' => '以倒下的角色为对象',
        'Summon' => '只以召唤物为对象', 'Charge' => '只以蓄力 / 咏唱中的角色为对象', 'LowHpRate' => '优先选择生命比例最低者',
    ];

    /** Status modifiers: legacy order and wording (生命上限 / 魔力上限 / 力量 …). */
    private const MODIFIED = [
        'MAXHP' => '生命上限', 'MAXSP' => '魔力上限', 'STR' => '力量', 'INT' => '智慧', 'DEX' => '敏捷',
        'SPD' => '速度', 'LUK' => '幸运', 'ATK' => '物理攻击', 'MATK' => '魔法攻击', 'DEF' => '物理防御', 'MDEF' => '魔法防御',
    ];

    private const BONUS = [
        'P_MAXHP' => '最大生命', 'P_MAXSP' => '最大魔力', 'P_STR' => '力量', 'P_INT' => '智慧',
        'P_DEX' => '敏捷', 'P_SPD' => '速度', 'P_LUK' => '幸运',
    ];

    /** Fields that carry no rule of their own (identity, art, or text shown elsewhere). */
    public const IGNORED_SKILL = ['name', 'name2', 'img', 'no', 'exp', 'learn'];

    public const IGNORED_ITEM = ['name', 'img', 'no', 'type', 'type2', 'buy', 'sell', 'need', 'option'];

    /**
     * Skills whose handler in Domain\Combat\Effects is written by ID rather than by fields.
     * The wording restates that handler so players can read the actual rule.
     */
    public const SPECIAL_EFFECTS = [
        1020 => '伤害改为扣减目标的魔力，不扣生命。',
        1021 => '按伤害值同时扣减目标的生命和魔力。',
        1022 => '使用者在后卫时威力 4 倍；攻击后移动到前卫。',
        1023 => '使用者在前卫时威力 3 倍；攻击后移动到后卫。',
        1024 => '平分双方的生命：差值的一半从较高的一方转给较低的一方；从目标吸取时若这一半达到 1000 以上，只转移 500。',
        1025 => '平分双方的魔力：差值的一半从较高的一方转给较低的一方；从目标吸取时若这一半达到 1000 以上，只转移 500。',
        1116 => '造成的伤害等于使用者已损失的生命（最大生命 − 当前生命），不受防御影响。',
        1119 => '强化除使用者以外的全体友方。',
        1200 => '目标处于中毒状态时威力 6 倍。',
        1208 => '使中毒的敌人立即受到一次毒伤，倍率 = (ln((智慧 + 22) ÷ 10) − 0.8) ÷ 0.85。',
        1209 => '只对中毒的友方生效：先获得能力加成，再解除中毒。',
        1220 => '毒耐性提高剩余部分的 50%（0% → 50% → 75% …）。',
        2030 => '吸取生命：按伤害值回复使用者的生命。',
        2031 => '吸取生命：按伤害值回复使用者的生命。',
        2032 => '50% 概率使目标生命直接归零。',
        2055 => '威力乘以（我方倒下或属于不死系的人数 + 1）。',
        2056 => '复活倒下的友方并回满生命，同时附带列出的弱化（召唤物和首领不能复活）。',
        2057 => '只能在自己生命 60% 以下时使用，每场一次；外形改变，获得强化并回复最大生命的一半。',
        2090 => '吸取魔力：按伤害值扣减目标魔力并回复使用者魔力（无视防御）。',
        2091 => '吸取魔力：按伤害值扣减目标魔力并回复使用者魔力（无视防御）。',
        2110 => '只对蓄力 / 咏唱中的目标生效，使其行动延迟。',
        2111 => '只对蓄力 / 咏唱中的目标生效，使其行动延迟。',
        3005 => '目标生命在 30% 以下时回复量加倍。',
        3010 => '回复目标最大魔力的 30%。',
        3011 => '回复目标最大魔力的 50%。',
        3012 => '扣除最大生命的 30%（不会因此倒下），回复最大魔力的 70%。',
        3013 => '交换生命与魔力的百分比。',
        3020 => '最大魔力 +20%。',
        3040 => '复活倒下的友方，按威力回复生命。',
        3050 => '使未在咏唱的其他友方立即行动。',
        3055 => '正在咏唱魔法的友方，剩余的行动进度缩短 60%。',
        3060 => '使目标获得一次屏障：下一次受到的攻击伤害变为 0。',
        3120 => '回复 50 + 最大生命的 10%。',
        3121 => '回复 50 + 最大生命的 20%。',
        3122 => '回复使用者已损失生命的 60%。',
        3300 => '只对召唤物生效。',
        3301 => '只对召唤物生效。',
        3302 => '只对召唤物生效。',
        3303 => '只对召唤物生效。',
        3304 => '只对召唤物生效。',
        3305 => '只对召唤物生效。',
        3306 => '只对召唤物生效。',
        3307 => '只对召唤物生效。',
        3308 => '只对召唤物生效。',
        3310 => '只对召唤物生效。',
        3900 => '使用者自己进入中毒状态。',
        3901 => '使用者自己受到 9999 点伤害。',
        4000 => '把友方移回各自战斗开始时的位置。',
        5002 => '吸取生命：按伤害值回复使用者的生命（无视防御）。',
        5006 => '对自己使用时退到后卫；其他友方移动到前卫并获得强化。',
        5022 => '对其他友方回复生命并强化。',
        5030 => '复活倒下的友方，按威力回复生命。',
        5060 => '目标物理 / 魔法防御 −30%，使用者物理 / 魔法防御 +30%。',
        5063 => '复活倒下的友方，按威力回复生命。',
        5067 => '使目标获得一次屏障：下一次受到的攻击伤害变为 0。',
        5803 => '随机召唤一只：骷髅勇士、骷髅士兵、骷髅箭手、骨头萨满或模仿者。',
        9000 => '不发动任何效果：本行的判定满足时，继续检查下一行的判定，全部满足才执行之后那一行的技能。见高级指南“多重判定”。',
    ];

    public function __construct(private ContentCatalog $catalog) {}

    private static function part(array $pair): array
    {
        return ['tone' => $pair[0], 'text' => $pair[1]];
    }

    public static function stats(): array
    {
        return array_combine(self::STAT_KEYS, array_map(static fn (string $key): string => __('hof.stats.'.$key), self::STAT_KEYS));
    }

    public static function money(int|string $amount): string
    {
        return '$ '.number_format((int) $amount);
    }

    public static function percent(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals);

        return (str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text).'%';
    }

    public function name(string $kind, string|int $id): string
    {
        if ($kind === 'skills' && (string) $id === '9000') {
            return $this->catalog->get('skills', '9000')['name'];
        }
        if (! $this->catalog->has($kind, $id)) {
            return '未知';
        }
        $record = $this->catalog->get($kind, $id);

        return (string) ($record['name'] ?? $record['name_male'] ?? '未知');
    }

    /** Equipment/consumable numbers in legacy ShowItemDetail() order. */
    public function itemStats(array $item): array
    {
        $parts = [];
        if (! empty($item['atk'][0])) {
            $parts[] = ['tone' => 'dmg', 'text' => '物理攻击 '.$item['atk'][0]];
        }
        if (! empty($item['atk'][1])) {
            $parts[] = ['tone' => 'spdmg', 'text' => '魔法攻击 '.$item['atk'][1]];
        }
        if (! empty($item['def'])) {
            $parts[] = ['tone' => 'recover', 'text' => '物理防御 '.(int) $item['def'][0].'+'.(int) $item['def'][1]];
            $parts[] = ['tone' => 'support', 'text' => '魔法防御 '.(int) $item['def'][2].'+'.(int) $item['def'][3]];
        }
        if (! empty($item['P_SUMMON'])) {
            $parts[] = ['tone' => 'support', 'text' => '召唤力 +'.$item['P_SUMMON'].'%'];
        }
        foreach ([0 => '物理', 1 => '魔法'] as $index => $label) {
            if (! empty($item['P_PIERCE'][$index])) {
                $parts[] = ['tone' => 'dmg', 'text' => '无视'.$label.'防御伤害 +'.$item['P_PIERCE'][$index]];
            }
        }
        foreach (self::BONUS as $key => $label) {
            if (! empty($item[$key])) {
                $parts[] = ['tone' => 'charge', 'text' => $label.' '.self::signed((int) $item[$key])];
            }
        }
        foreach (['M_MAXHP' => '最大生命', 'M_MAXSP' => '最大魔力'] as $key => $label) {
            if (! empty($item[$key])) {
                $parts[] = ['tone' => 'charge', 'text' => $label.' '.self::signed((int) $item[$key]).'%'];
            }
        }
        if (isset($item['handle'])) {
            $parts[] = ['tone' => 'charge', 'text' => '重量 '.$item['handle']];
        }
        if (! empty($item['dh'])) {
            $parts[] = ['tone' => 'charge', 'text' => '双手'];
        }
        if (! empty($item['Add'])) {
            $parts[] = ['tone' => 'support', 'text' => '制作时附加「'.$this->enchantName((string) $item['Add']).'」'];
        }

        return $parts;
    }

    /**
     * Every rule field of a skill, in legacy ShowSkillDetail() order, as [tone, text] parts.
     * Description text and learning cost are rendered separately by the views.
     */
    public function skillParts(array $skill): array
    {
        $parts = [];
        if (! empty($skill['passive'])) {
            $parts[] = ['tone' => 'charge', 'text' => '被动'];
        }
        if (isset($skill['target'])) {
            [$side, $style, $count] = $skill['target'] + [null, null, 1];
            $parts[] = self::part(self::TARGET_SIDE[$side] ?? ['', (string) $side]);
            $parts[] = self::part(self::TARGET_STYLE[$style] ?? ['', (string) $style]);
            if ($style === 'multi' && (int) $count > 1) {
                $parts[] = ['tone' => 'spdmg', 'text' => (int) $count.' 次'];
            }
        }
        if (! empty($skill['sacrifice'])) {
            $parts[] = ['tone' => 'dmg', 'text' => '牺牲最大生命的 '.$skill['sacrifice'].'%（后卫时加倍）'];
        }
        if (isset($skill['sp'])) {
            $parts[] = ['tone' => 'support', 'text' => '消耗 '.(int) $skill['sp'].' 魔力'];
        }
        if (! empty($skill['MagicCircleDeleteTeam'])) {
            $parts[] = ['tone' => 'support', 'text' => '消耗我方魔法阵 '.$skill['MagicCircleDeleteTeam'].' 个'];
        }
        if (! empty($skill['pow'])) {
            $count = ($skill['target'][1] ?? '') === 'multi' ? 1 : (int) ($skill['target'][2] ?? 1);
            $parts[] = ['tone' => ! empty($skill['support']) ? 'recover' : 'dmg', 'text' => '威力 '.$skill['pow'].'%'.($count > 1 ? ' × '.$count : '')];
        }
        if ((int) ($skill['type'] ?? 0) === 1) {
            $parts[] = ['tone' => 'spdmg', 'text' => '魔法'];
        } elseif (! empty($skill['pow']) && empty($skill['support'])) {
            $parts[] = ['tone' => 'dmg', 'text' => ($skill['inf'] ?? '') === 'dex' ? '物理（敏捷）' : '物理'];
        }
        if (! empty($skill['support'])) {
            $parts[] = ['tone' => 'recover', 'text' => '辅助'];
        }
        if (! empty($skill['pierce'])) {
            $parts[] = ['tone' => 'dmg', 'text' => '无视防御'];
        }
        if (! empty($skill['quick'])) {
            $parts[] = ['tone' => 'charge', 'text' => '召唤物立即行动'];
        }
        if (! empty($skill['invalid'])) {
            $parts[] = ['tone' => 'charge', 'text' => '前卫无法保护'];
        }
        if (isset($skill['priority'])) {
            $parts[] = ['tone' => 'support', 'text' => self::PRIORITY[$skill['priority']] ?? (string) $skill['priority']];
        }
        if (! empty($skill['CurePoison'])) {
            $parts[] = ['tone' => 'support', 'text' => '解毒'];
        }
        if (! empty($skill['poison'])) {
            $parts[] = ['tone' => 'spdmg', 'text' => '中毒 '.$skill['poison'].'%'];
        }
        if (! empty($skill['knockback'])) {
            $parts[] = ['tone' => 'dmg', 'text' => '击退至后卫'];
        }
        if (! empty($skill['move'])) {
            $parts[] = ['tone' => 'support', 'text' => '目标移至'.__('hof.positions.'.$skill['move'])];
        }
        if (! empty($skill['umove'])) {
            $parts[] = ['tone' => 'support', 'text' => '使用者移至'.__('hof.positions.'.$skill['umove'])];
        }
        if (! empty($skill['delay'])) {
            $parts[] = ['tone' => 'support', 'text' => '延迟 -'.$skill['delay']];
        }
        if (! empty($skill['SpRecoveryRate'])) {
            $parts[] = ['tone' => 'support', 'text' => '回复魔力 √最大魔力 × '.$skill['SpRecoveryRate']];
        }
        foreach (['HpRegen' => '生命', 'SpRegen' => '魔力'] as $key => $label) {
            if (! empty($skill[$key])) {
                $parts[] = ['tone' => 'recover', 'text' => '每次行动回复'.$label.' '.$skill[$key].'%'];
            }
        }
        if (! empty($skill['MagicCircleAdd'])) {
            $parts[] = ['tone' => 'charge', 'text' => '我方魔法阵 +'.$skill['MagicCircleAdd']];
        }
        if (! empty($skill['MagicCircleDeleteEnemy'])) {
            $parts[] = ['tone' => 'dmg', 'text' => '敌方魔法阵 -'.$skill['MagicCircleDeleteEnemy']];
        }
        foreach (['Up' => ['charge', '+'], 'Down' => ['dmg', '-']] as $operation => [$tone, $sign]) {
            foreach (self::MODIFIED as $stat => $label) {
                if (! empty($skill[$operation.$stat])) {
                    $parts[] = ['tone' => $tone, 'text' => $label.' '.$sign.$skill[$operation.$stat].'%'];
                }
            }
        }
        foreach (['STR', 'INT', 'DEX', 'SPD', 'LUK'] as $stat) {
            if (! empty($skill['Plus'.$stat])) {
                $parts[] = ['tone' => 'charge', 'text' => self::MODIFIED[$stat].' +'.$skill['Plus'.$stat]];
            }
        }
        foreach (self::BONUS as $key => $label) {
            if (! empty($skill[$key])) {
                $parts[] = ['tone' => 'charge', 'text' => $label.' '.self::signed((int) $skill[$key])];
            }
        }
        if (! empty($skill['summon'])) {
            $parts[] = ['tone' => 'support', 'text' => '召唤 '.implode('、', array_map(fn ($id) => $this->name('monsters', $id), (array) $skill['summon']))];
        }
        if (! empty($skill['charge'][0]) || ! empty($skill['charge'][1])) {
            $parts[] = ['tone' => '', 'text' => '（准备 '.(int) ($skill['charge'][0] ?? 0).' : 僵直 '.(int) ($skill['charge'][1] ?? 0).'）'];
        }
        if (! empty($skill['limit'])) {
            $parts[] = ['tone' => '', 'text' => '需要装备：'.implode('、', array_keys($skill['limit']))];
        }

        return $parts;
    }

    /** Fields of a skill or item that this class does not render; used by the completeness test. */
    public static function skillFields(): array
    {
        $fields = ['passive', 'target', 'sacrifice', 'sp', 'MagicCircleDeleteTeam', 'pow', 'type', 'inf', 'support', 'pierce',
            'quick', 'invalid', 'priority', 'CurePoison', 'poison', 'knockback', 'move', 'umove', 'delay', 'SpRecoveryRate',
            'HpRegen', 'SpRegen', 'MagicCircleAdd', 'MagicCircleDeleteEnemy', 'summon', 'charge', 'limit'];
        foreach (['Up', 'Down'] as $operation) {
            foreach (array_keys(self::MODIFIED) as $stat) {
                $fields[] = $operation.$stat;
            }
        }
        foreach (['STR', 'INT', 'DEX', 'SPD', 'LUK'] as $stat) {
            $fields[] = 'Plus'.$stat;
        }

        return [...$fields, ...array_keys(self::BONUS)];
    }

    public static function itemFields(): array
    {
        return ['atk', 'def', 'P_SUMMON', 'P_PIERCE', ...array_keys(self::BONUS), 'M_MAXHP', 'M_MAXSP', 'handle', 'dh', 'Add'];
    }

    /** Fill the legacy "←←" placeholder: "自己的HP ←←(%)以上" becomes "自己的HP 50% 以上", or "N% 以上" without a value. */
    public function condition(int|string $id, ?int $quantity = null): string
    {
        $text = str_replace(['←←(%)', '←←', '**'], ['{q}%', '{q}', '{q}'], (string) ($this->catalog->get('conditions', $id)['exp'] ?? ''));
        $text = preg_replace('/\s+/u', ' ', str_replace('{q}', ' '.($quantity ?? 'N').' ', $text));

        return trim(str_replace(' %', '%', $text));
    }

    public function enchantName(string $id): string
    {
        $operations = $this->catalog->get('enchants', $id)['operations'];
        $names = [];
        foreach ($operations as $operation) {
            if ($operation['path'] === ['AddName']) {
                $names[] = trim((string) $operation['value']).(isset($operation['when_type2']) ? '（'.self::type2($operation['when_type2']).'）' : '');
            }
        }

        return $names ? implode(' / ', $names) : $this->enchantEffect($id);
    }

    /** Readable effect of one enchantment, grouped by the item class it applies to. */
    public function enchantEffect(string $id): string
    {
        $groups = [];
        foreach ($this->catalog->get('enchants', $id)['operations'] as $operation) {
            $path = implode('.', $operation['path']);
            if (in_array($path, ['option', 'AddName'], true)) {
                continue;
            }
            $label = match ($path) {
                'atk.0' => '物理攻击', 'atk.1' => '魔法攻击', 'def.0' => '物理防御', 'def.2' => '魔法防御',
                'M_MAXHP' => '最大生命', 'M_MAXSP' => '最大魔力', default => self::BONUS[$path] ?? '能力',
            };
            $value = $operation['value'];
            $text = $label.' '.match ($operation['operation']) {
                'multiply_round' => '+'.round(($value - 1) * 100).'%',
                default => self::signed((int) $value).(str_starts_with($path, 'M_') ? '%' : ''),
            };
            $groups[$operation['when_type2'] ?? ''][] = $text;
        }
        $lines = [];
        foreach ($groups as $type2 => $effects) {
            $lines[] = ($type2 === '' ? '' : self::type2($type2).'：').implode('，', $effects);
        }

        return implode('；', $lines);
    }

    public static function type2(string $type2): string
    {
        return ['WEAPON' => '武器', 'GUARD' => '防具'][$type2] ?? '其他';
    }

    public static function signed(int $value): string
    {
        return $value >= 0 ? '+'.$value : (string) $value;
    }

    /** Respawn rule for a recurring boss, in hours. */
    public static function cycle(int|array $cycle): string
    {
        if (is_int($cycle)) {
            return self::duration($cycle);
        }

        return sprintf('UTC %02d:00–%02d:59 内被击倒时 %s，其他时间 %s', $cycle['hour'], $cycle['hour'], self::duration($cycle['matching_seconds']), self::duration($cycle['otherwise_seconds']));
    }

    public static function duration(int $seconds): string
    {
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400).' 天';
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600).' 小时';
        }
        if ($seconds % 60 === 0) {
            return ($seconds / 60).' 分钟';
        }

        return $seconds.' 秒';
    }
}
