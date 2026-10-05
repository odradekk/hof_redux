<?php

declare(strict_types=1);

namespace App\Application\World;

use App\Application\Player\ItemDetails;
use App\Domain\Content\ContentCatalog;

final class CatalogPresenter
{
    public function __construct(private ContentCatalog $catalog, private ItemDetails $items) {}

    public function card(string $kind, string|int $id, array $record): array
    {
        $card = ['title' => $record['name'] ?? '', 'description' => $record['exp'] ?? '', 'images' => [], 'facts' => []];
        if (isset($record['img'])) {
            $card['images'][] = ['path' => 'image/'.(in_array($kind, ['items', 'skills'], true) ? 'icon/' : 'char/').basename($record['img']), 'label' => $card['title']];
        }
        switch ($kind) {
            case 'jobs':
                $card['title'] = $record['name_male'];
                foreach (['male' => '男性', 'female' => '女性'] as $gender => $label) {
                    $card['images'][] = ['path' => 'image/char/'.basename($record['img_'.$gender]), 'label' => $label.' · '.$record['name_'.$gender]];
                }
                $card['facts']['可用装备'] = implode('、', $record['equip']);
                $card['facts']['生命成长'] = '× '.$record['coe'][0];
                $card['facts']['精神成长'] = '× '.$record['coe'][1];
                if (! empty($record['change'])) {
                    $card['facts']['进阶职业'] = $this->names('jobs', $record['change']);
                }
                break;
            case 'items':
                $item = $this->items->resolve(['item_id' => (string) $id]);
                $card['description'] = $item['option'] ?? '';
                $card['facts'] = ['种类' => $item['type'], '基础价格' => number_format((int) $item['buy']).' 金钱', '出售价格' => number_format($item['sell_price']).' 金钱'];
                if (isset($item['handle'])) {
                    $card['facts']['重量'] = (string) $item['handle'];
                }
                if (! empty($item['dh'])) {
                    $card['facts']['持握'] = '双手';
                }
                $this->equipmentFacts($card['facts'], $item);
                if (! empty($item['need'])) {
                    $materials = [];
                    foreach ($item['need'] as $material => $count) {
                        $materials[] = $this->name('items', $material).' × '.$count;
                    }
                    $card['facts']['制作材料'] = implode('、', $materials);
                }
                break;
            case 'conditions':
                $card['title'] = '行动条件';
                $card['description'] = $record['exp'];
                break;
            case 'monsters':
                $card['facts']['等级'] = (string) ($record['level'] ?? '未知');
                $boss = isset($record['UnionName']) || isset($record['cycle']);
                $card['facts']['生命'] = $boss || ! is_numeric($record['maxhp'] ?? null) ? '未知' : number_format((int) $record['maxhp']);
                if (isset($record['position'])) {
                    $card['facts']['位置'] = $record['position'] === 'front' ? '前排' : '后排';
                }
                if (! empty($record['action'])) {
                    $card['facts']['使用技能'] = $this->names('skills', array_unique($record['action']));
                }
                if (! empty($record['itemtable'])) {
                    $card['facts']['可能掉落'] = $this->names('items', array_keys($record['itemtable']));
                }
                if (isset($record['LevelLimit'])) {
                    $card['facts']['挑战队伍总等级上限'] = (string) $record['LevelLimit'];
                }
                if ($record['preview_only'] ?? false) {
                    $card['description'] = '资料展示，不参与战斗。';
                }
                break;
            case 'skills':
                $card['facts']['类别'] = ! empty($record['passive']) ? '被动技能' : (((int) ($record['type'] ?? 0)) === 1 ? '魔法技能' : '战斗技能');
                if (isset($record['sp'])) {
                    $card['facts']['消耗'] = (string) $record['sp'].' SP';
                }
                if (isset($record['learn'])) {
                    $card['facts']['学习费用'] = (string) $record['learn'].' 技能点';
                }
                if (isset($record['target'])) {
                    $target = $record['target'];
                    $card['facts']['目标'] = (['self' => '自身', 'friend' => '友方', 'enemy' => '敌方', 'all' => '双方'][$target[0]] ?? '指定目标').' · '.(['individual' => '单体', 'multi' => '多体', 'all' => '全体'][$target[1]] ?? '指定范围');
                }
                if (isset($record['pow'])) {
                    $card['facts']['威力'] = $record['pow'].'%';
                }
                if (! empty($record['summon'])) {
                    $card['facts']['召唤'] = $this->names('monsters', (array) $record['summon']);
                }
                if ((string) $id === '3113') {
                    $card['description'] = '仅保留资料，当前不开放学习或使用。';
                }
                break;
            case 'enchants':
                $effects = [];
                $card['title'] = '附魔效果';
                foreach ($record['operations'] as $operation) {
                    $path = implode('.', $operation['path']);
                    if ($path === 'AddName') {
                        $card['title'] = trim((string) $operation['value']);

                        continue;
                    }
                    if ($path === 'option') {
                        continue;
                    }
                    $label = $this->attribute($path);
                    $value = $operation['value'];
                    $effects[] = $label.match ($operation['operation']) {
                        'add' => ($value >= 0 ? ' +' : ' ').$value.(str_starts_with($path, 'M_') ? '%' : ''), 'multiply_round' => ' × '.$value, default => ' '.$value
                    };
                }
                $card['description'] = implode('，', $effects);
                break;
        }

        return $card;
    }

    private function name(string $kind, string|int $id): string
    {
        if ($kind === 'skills' && (string) $id === '9000') {
            return '等待';
        }
        if (! $this->catalog->has($kind, $id)) {
            return '未知';
        }
        $record = $this->catalog->get($kind, $id);

        return $record['name'] ?? $record['name_male'] ?? '未知';
    }

    private function names(string $kind, array $ids): string
    {
        return implode('、', array_map(fn ($id) => $this->name($kind, $id), $ids));
    }

    private function attribute(string $key): string
    {
        return ['atk.0' => '物理攻击', 'atk.1' => '魔法攻击', 'def.0' => '物理防御', 'def.1' => '物理减伤率', 'def.2' => '魔法防御', 'def.3' => '魔法减伤率', 'P_STR' => '力量', 'P_INT' => '智力', 'P_DEX' => '技巧', 'P_SPD' => '速度', 'P_LUK' => '幸运', 'P_MAXHP' => '生命', 'P_MAXSP' => '精神', 'M_MAXHP' => '生命', 'M_MAXSP' => '精神'][$key] ?? '能力';
    }

    private function equipmentFacts(array &$facts, array $item): void
    {
        foreach (['atk', 'def'] as $group) {
            foreach ($item[$group] ?? [] as $index => $value) {
                if ($value) {
                    $facts[$this->attribute($group.'.'.$index)] = (string) $value.($group === 'def' && in_array($index, [1, 3], true) ? '%' : '');
                }
            }
        }
        foreach (['P_STR', 'P_INT', 'P_DEX', 'P_SPD', 'P_LUK', 'P_MAXHP', 'P_MAXSP', 'M_MAXHP', 'M_MAXSP'] as $key) {
            if (! empty($item[$key])) {
                $facts[$this->attribute($key).'加成'] = (string) $item[$key].(str_starts_with($key, 'M_') ? '%' : '');
            }
        }
    }
}
