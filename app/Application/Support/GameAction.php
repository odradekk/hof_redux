<?php

namespace App\Application\Support;

use App\Models\InventoryItem;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GameAction
{
    public function execute(int $actorId, string $command, string $key, array $payload, Closure $callback): array
    {
        $this->ensure(Str::isUuid($key), 'A valid operation ID is required.');
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
        $seed = random_int(1, 2147483646);

        return DB::transaction(function () use ($actorId, $command, $key, $hash, $callback, $seed) {
            $this->lock();
            $user = User::query()->lockForUpdate()->findOrFail($actorId);
            $operation = DB::table('operations')->where(['user_id' => $actorId, 'command' => $command, 'operation_id' => $key])->first();
            if ($operation) {
                $this->ensure(hash_equals($operation->request_hash, $hash), 'This operation ID was already used for different input.');

                return json_decode($operation->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $id = DB::table('operations')->insertGetId(['user_id' => $actorId, 'command' => $command, 'operation_id' => $key, 'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $result = $callback($user, $id, $seed);
            DB::table('operations')->where('id', $id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    private function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonical($value);
            }
        }

        return $payload;
    }

    // All gameplay mutations take this lock before any row lock. This deliberately
    // trades throughput for a single, verifiable lock order in the first release.
    public function lock(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(72641001)');
        }
    }

    public function ensure(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['game' => $message]);
        }
    }

    public function ledger(int $userId, ?int $operationId, string $kind, int $amount, string $reason, ?string $itemId = null, array $metadata = []): void
    {
        DB::table('asset_entries')->insert(['user_id' => $userId, 'operation_id' => $operationId, 'kind' => $kind, 'amount' => $amount, 'item_id' => $itemId, 'reason' => $reason, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function money(User $user, int $delta, ?int $operationId, string $reason): void
    {
        $this->ensure($delta >= -$user->money && $delta <= 9000000000000000 - $user->money, 'Insufficient money or balance limit exceeded.');
        $user->money += $delta;
        $user->save();
        $this->ledger($user->id, $operationId, 'money', $delta, $reason);
    }

    public function stamina(User $user, int $cost, int $operationId, string $reason): void
    {
        $this->ensure($cost >= 0 && $cost <= 100, 'Invalid stamina cost.');
        $at = now();
        $seconds = max(0, $at->getTimestamp() - $user->stamina_updated_at->getTimestamp());
        $available = min(8640000, $user->stamina_units + $seconds * 500);
        $this->ensure($available >= $cost * 86400, 'Not enough stamina.');
        $user->stamina_units = $available - $cost * 86400;
        $user->stamina_updated_at = $at;
        $user->save();
        $this->ledger($user->id, $operationId, 'stamina', -$cost, $reason);
    }

    public function addItem(User $user, string $itemId, int $quantity, ?int $operationId, string $reason, array $attributes = []): InventoryItem
    {
        $this->ensure($quantity > 0 && $quantity <= 1000000, 'Invalid item quantity.');
        $item = InventoryItem::create(['user_id' => $user->id, 'item_id' => $itemId, 'quantity' => $quantity, 'refinement' => $attributes['refinement'] ?? 0, 'enchantments' => $attributes['enchantments'] ?? [], 'location' => 'backpack']);
        $this->ledger($user->id, $operationId, 'item', $quantity, $reason, $itemId, ['inventory_id' => $item->id, 'refinement' => $item->refinement, 'enchantments' => $item->enchantments]);

        return $item;
    }

    public function takeItem(User $user, int $inventoryId, int $quantity, int $operationId, string $reason): array
    {
        $item = InventoryItem::query()->where('user_id', $user->id)->where('location', 'backpack')->lockForUpdate()->findOrFail($inventoryId);
        $this->ensure($quantity > 0 && $quantity <= $item->quantity, 'Invalid item quantity.');
        $result = ['item_id' => $item->item_id, 'quantity' => $quantity, 'refinement' => $item->refinement, 'enchantments' => $item->enchantments];
        if ($quantity === $item->quantity) {
            $item->delete();
        } else {
            $item->quantity -= $quantity;
            $item->save();
        }
        $this->ledger($user->id, $operationId, 'item', -$quantity, $reason, $item->item_id, ['inventory_id' => $inventoryId, 'refinement' => $item->refinement, 'enchantments' => $item->enchantments]);

        return $result;
    }
}
