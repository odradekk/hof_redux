<?php

declare(strict_types=1);

namespace App\Http\View;

/** Maps already resolved ItemDetails data to component-only scalar arrays. */
final class ItemLines
{
    public static function fromCatalog(array $item): array
    {
        $stats = [];
        foreach (($item['atk'] ?? []) as $i => $value) {
            if ($value) {
                $stats[] = ['tone' => $i === 0 ? 'dmg' : 'spdmg', 'text' => ($i === 0 ? '物理攻击' : '魔法攻击').'：'.$value];
            }
        }
        foreach ([0 => ['物理防御', 'recover'], 2 => ['魔法防御', 'support']] as $i => [$label, $tone]) {
            if (isset($item['def'][$i])) {
                $stats[] = ['tone' => $tone, 'text' => $label.'：'.$item['def'][$i].'+'.($item['def'][$i + 1] ?? 0)];
            }
        }
        if (isset($item['handle'])) {
            $stats[] = ['tone' => 'charge', 'text' => '重量：'.$item['handle']];
        }
        foreach ($item as $key => $value) {
            if (str_starts_with($key, 'P_') || str_starts_with($key, 'M_')) {
                $label = __('hof.item_fields.'.$key);
                if ($label === 'hof.item_fields.'.$key) {
                    throw new \UnexpectedValueException('Missing item term: '.$key);
                }
                $text = is_array($value) ? implode(' / ', $value) : (string) $value;
                $stats[] = ['tone' => 'support', 'text' => $label.' +'.$text.(str_starts_with($key, 'M_') || $key === 'P_SUMMON' ? '%' : '')];
            }
        }
        $name = $item['name'];
        if (! empty($item['refine'])) {
            $name = preg_replace('/\+'.(int) $item['refine'].'\s*/u', '', $name, 1);
        }

        return ['icon' => isset($item['img']) ? 'image/icon/'.basename($item['img']) : null,
            'name' => $name, 'refine' => (int) ($item['refine'] ?? 0), 'type' => $item['type'], 'qty' => 1,
            'stats' => $stats, 'option' => $item['option'] ?? '', 'note' => $item['note'] ?? $item['exp'] ?? ''];
    }

    public static function fromInventory(array $row, array $resolved): array
    {
        return array_replace(self::fromCatalog($resolved), ['qty' => (int) ($row['quantity'] ?? 1)]);
    }

    public static function skill(array $skill, int|string|null $id = null): array
    {
        return ['id' => $id ?? $skill['no'] ?? null, 'icon' => isset($skill['img']) ? 'image/icon/'.basename($skill['img']) : null,
            'name' => $skill['name'], 'sp' => (int) ($skill['sp'] ?? 0), 'learn' => (int) ($skill['learn'] ?? 0)];
    }
}
