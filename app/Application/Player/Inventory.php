<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\Character;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Inventory mutations require the outer GameAction transaction and locked owner. */
final class Inventory
{
    public function __construct(private GameAction $actions, private ContentCatalog $catalog) {}

    public function owned(User $user, int $id): InventoryItem
    {
        return InventoryItem::where('user_id', $user->id)->where('location', 'warehouse')->lockForUpdate()->findOrFail($id);
    }

    public function consumeBase(User $user, string $itemId, int $quantity, int $operation, string $reason): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be positive.');
        }
        $rows = $user->inventory()->where('location', 'warehouse')->where('item_id', $itemId)
            ->where('refinement', 0)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (InventoryItem $item) => empty($item->enchantments));
        if ($rows->sum('quantity') < $quantity) {
            self::reject('Not enough materials or consumables.');
        }
        foreach ($rows as $item) {
            $take = min($quantity, $item->quantity);
            $this->actions->takeItem($user, $item->id, $take, $operation, $reason);
            $quantity -= $take;
            if ($quantity === 0) {
                break;
            }
        }
    }

    public function unequip(User $user, Character $character, int $operation, ?string $slot = null): void
    {
        if ($slot !== null && ! in_array($slot, ['weapon', 'shield', 'armor', 'item'], true)) {
            self::reject('Unknown equipment slot.');
        }
        $query = $character->equipment()->orderBy('id')->lockForUpdate();
        if ($slot !== null) {
            $query->where('slot', $slot);
        }
        foreach ($query->get() as $item) {
            $this->returnItem($user, $item, $operation);
        }
    }

    private function returnItem(User $user, InventoryItem $item, int $operation): void
    {
        $previous = ['character_id' => $item->character_id, 'slot' => $item->slot];
        $item->forceFill(['location' => 'warehouse', 'character_id' => null, 'slot' => null])->save();
        $this->actions->ledger($user->id, $operation, 'item_move', 1, 'unequip', $item->item_id, $previous + ['inventory_id' => $item->id]);
    }

    public function equip(User $user, Character $character, int $inventoryId, int $operation): void
    {
        $item = $this->owned($user, $inventoryId);
        $data = $this->catalog->get('items', $item->item_id);
        $job = $this->catalog->get('jobs', $character->job_id);
        $slot = PlayerRules::slot($data['type']);
        if ($slot === null || ! in_array($data['type'], $job['equip'] ?? [], true)) {
            self::reject('This job cannot equip this item.');
        }
        $equipped = $character->equipment()->orderBy('id')->lockForUpdate()->get();
        $remove = [];
        $weight = (int) ($data['handle'] ?? 0);
        foreach ($equipped as $old) {
            $oldData = $this->catalog->get('items', $old->item_id);
            $replaced = $old->slot === $slot
                || ($slot === 'weapon' && ! empty($data['dh']) && $old->slot === 'shield')
                || ($slot === 'shield' && $old->slot === 'weapon' && ! empty($oldData['dh']));
            if ($replaced) {
                $remove[] = $old;
            } else {
                $weight += (int) ($oldData['handle'] ?? 0);
            }
        }
        if ($weight > PlayerRules::capacity($character)) {
            self::reject('Equipment exceeds this character’s weight capacity.');
        }
        foreach ($remove as $old) {
            $this->returnItem($user, $old, $operation);
        }
        // A stack is split before equipping, so the equipped row always owns one item.
        if ($item->quantity > 1) {
            $item->decrement('quantity');
            $item = InventoryItem::create([
                'user_id' => $user->id, 'item_id' => $item->item_id, 'quantity' => 1,
                'refinement' => $item->refinement, 'enchantments' => $item->enchantments,
                'location' => 'warehouse',
            ]);
        }
        $item->forceFill(['location' => 'equipped', 'character_id' => $character->id, 'slot' => $slot])->save();
        $this->actions->ledger($user->id, $operation, 'item_move', 1, 'equip', $item->item_id,
            ['inventory_id' => $item->id, 'character_id' => $character->id, 'slot' => $slot]);
    }

    public static function reject(string $message): never
    {
        throw ValidationException::withMessages(['game' => $message]);
    }
}
