<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Domain\Content\ContentCatalog;
use App\Models\InventoryItem;

final class ItemDetails
{
    public function __construct(private ContentCatalog $catalog) {}

    public function resolve(InventoryItem|array $inventory): array
    {
        $itemId = (string) $inventory['item_id'];
        $item = $this->catalog->get('items', $itemId);
        $item['no'] = $itemId;
        $item['base_name'] = $item['name'];
        $item['type2'] = in_array($item['type'], PlayerRules::WEAPONS, true) ? 'WEAPON'
            : (in_array($item['type'], ['盾', '书', '甲', '衣服', '长袍'], true) ? 'GUARD' : '其他');
        $refinement = (int) ($inventory['refinement'] ?? 0);
        if ($refinement < 0 || $refinement > 10) {
            throw new \UnexpectedValueException('Invalid refinement.');
        }
        $item['refine'] = $refinement;
        if ($refinement > 0) {
            foreach ($item['atk'] ?? [] as $i => $value) {
                $item['atk'][$i] = (int) ceil($value * (1 + $refinement ** 2 / 100));
            }
            foreach ($item['def'] ?? [] as $i => $value) {
                $item['def'][$i] = (int) ceil($value * (1 + 0.03 * $refinement));
            }
            $item['name'] = '+'.$refinement.' '.$item['name'];
        }
        // Order matters: refinement, special material, high option, low option.
        $enchantments = $inventory['enchantments'] ?? [];
        if (count($enchantments) > 3) {
            throw new \UnexpectedValueException('Too many enchantments.');
        }
        foreach ($enchantments as $enchantment) {
            $enchant = $this->catalog->get('enchants', (string) $enchantment);
            foreach ($enchant['operations'] as $operation) {
                if (isset($operation['when_type2']) && $operation['when_type2'] !== $item['type2']) {
                    continue;
                }
                $target = &$item;
                foreach ($operation['path'] as $part) {
                    $target = &$target[$part];
                }
                $value = $operation['value'];
                $target = match ($operation['operation']) {
                    'add' => ($target ?? 0) + $value,
                    'multiply_round' => (int) round(($target ?? 0) * $value),
                    'append' => ($target ?? '').$value,
                    'set' => $value,
                    default => throw new \UnexpectedValueException('Unknown enchantment operation.'),
                };
                unset($target);
            }
        }
        if (! empty($item['AddName'])) {
            $item['name'] = $item['AddName'].' '.$item['name'];
        }
        $item['sell_price'] = isset($item['sell']) ? (int) $item['sell'] : (int) round(($item['buy'] ?? 0) / 5);

        return $item;
    }
}
