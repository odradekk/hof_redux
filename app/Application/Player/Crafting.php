<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\User;
use Random\Randomizer;

/** Called only inside GameAction so all ingredient and result changes are atomic. */
final class Crafting
{
    public function __construct(private ContentCatalog $catalog, private Inventory $inventory, private GameAction $actions) {}

    public function create(User $user, string $itemId, ?string $additionalMaterial, int $operation, Randomizer $random): array
    {
        $recipe = $this->catalog->get('recipes', $itemId);
        $item = $this->catalog->get('items', $itemId);
        $ingredients = $recipe['ingredients'];
        $enchants = [];
        if ($additionalMaterial !== null && $additionalMaterial !== '') {
            $extra = $this->catalog->get('items', $additionalMaterial);
            if ((int) $additionalMaterial < 7000 || (int) $additionalMaterial >= 7200 || empty($extra['Add'])) {
                Inventory::reject('This item is not an eligible special crafting material.');
            }
            $this->catalog->get('enchants', (string) $extra['Add']);
            $enchants[] = (string) $extra['Add'];
            $ingredients[$additionalMaterial] = ($ingredients[$additionalMaterial] ?? 0) + 1;
        }
        foreach ($ingredients as $id => $quantity) {
            $this->inventory->consumeBase($user, (string) $id, (int) $quantity, $operation, 'craft');
        }
        $this->actions->money($user, -(int) $recipe['fee'], $operation, 'craft');
        $pools = $this->catalog->get('enchant_pools', $item['type']);
        $roll = $random->getInt(1, 9);
        if ($roll >= 4) {
            $enchants[] = (string) $pools['high'][$random->getInt(0, count($pools['high']) - 1)];
        }
        if ($roll <= 3 || $roll >= 7) {
            $enchants[] = (string) $pools['low'][$random->getInt(0, count($pools['low']) - 1)];
        }
        foreach ($enchants as $enchant) {
            $this->catalog->get('enchants', $enchant);
        }
        $result = $this->actions->addItem($user, $itemId, 1, $operation, 'craft', ['enchantments' => $enchants]);

        return ['message' => 'Item crafted.', 'inventory_id' => $result->id, 'enchantments' => $enchants];
    }

    public function refine(User $user, int $inventoryId, int $times, int $operation, Randomizer $random): array
    {
        $item = $this->inventory->owned($user, $inventoryId);
        $data = $this->catalog->get('items', $item->item_id);
        if (! in_array($data['type'], PlayerRules::REFINABLE, true) || $item->refinement >= 10) {
            Inventory::reject('This item cannot be refined.');
        }
        if ($times < 1 || $times > 10) {
            Inventory::reject('Choose one to ten refinement attempts.');
        }
        $times = min($times, 10 - $item->refinement);
        $price = (int) round($data['buy'] / 2);
        $refinement = $item->refinement;
        $attempts = [];
        // Nothing is removed until at least one paid attempt can be made.
        if ($user->money < $price) {
            return ['message' => 'Not enough money; item unchanged.', 'attempts' => [], 'inventory_id' => $item->id];
        }
        $attributes = $this->actions->takeItem($user, $item->id, 1, $operation, 'refine');
        for ($i = 0; $i < $times && $user->money >= $price; $i++) {
            $this->actions->money($user, -$price, $operation, 'refine');
            $success = $random->getInt(0, 99) < PlayerRules::refineChance($refinement);
            $attempts[] = ['from' => $refinement, 'to' => $refinement + 1, 'success' => $success, 'cost' => $price];
            if (! $success) {
                return ['message' => 'Refinement failed; item destroyed.', 'attempts' => $attempts, 'destroyed' => true];
            }
            $refinement++;
        }
        $attributes['refinement'] = $refinement;
        $result = $this->actions->addItem($user, $item->item_id, 1, $operation, 'refine', $attributes);

        return ['message' => count($attempts) < $times ? 'Refinement stopped: not enough money.' : 'Refinement succeeded.',
            'attempts' => $attempts, 'destroyed' => false, 'inventory_id' => $result->id, 'refinement' => $refinement];
    }
}
