<?php

namespace App\Application\Multiplayer;

use App\Application\Support\GameAction;
use App\Domain\Content\ContentCatalog;
use App\Models\AuctionEvent;
use App\Models\AuctionListing;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AuctionService
{
    public const TYPES = ['剑', '双手剑', '匕首', '魔杖', '杖', '弓', '鞭', '盾', '书', '甲', '衣服', '长袍', '道具', '材料'];

    public const MEMBERSHIP_PRICE = 11000;

    public const LISTING_FEE = 500;

    public const DURATIONS = [1, 3, 6, 12, 18, 24];

    public const MAX_ACTIVE = 100;

    public const LISTING_INTERVAL_SECONDS = 30;

    public const EXTENSION_MINUTES = 15;

    public function __construct(private GameAction $actions, private ContentCatalog $content) {}

    public static function minimumBid(int $price): int
    {
        return $price + max(100, intdiv($price, 10));
    }

    public function isMember(int $userId): bool
    {
        return InventoryItem::where('user_id', $userId)->where('item_id', '9000')->where('location', 'warehouse')->exists();
    }

    public function join(int $userId, string $key): array
    {
        return $this->actions->execute($userId, 'auction.join', $key, [], function (User $user, int $op) {
            $this->actions->ensure(! $this->isMember($user->id), 'You are already an auction member.');
            $this->actions->money($user, -self::MEMBERSHIP_PRICE, $op, 'auction membership');
            $this->actions->addItem($user, '9000', 1, $op, 'auction membership');

            return ['message' => 'Auction membership purchased.'];
        });
    }

    public function exhibit(int $userId, string $key, int $inventoryId, int $quantity, int $price, int $hours, string $comment = ''): array
    {
        return $this->actions->execute($userId, 'auction.exhibit', $key, compact('inventoryId', 'quantity', 'price', 'hours', 'comment'), function (User $user, int $op) use ($inventoryId, $quantity, $price, $hours, $comment) {
            $this->settleDueLocked();
            $user->refresh();
            $this->actions->ensure($this->isMember($user->id), 'Auction membership is required.');
            $this->actions->ensure(in_array($hours, self::DURATIONS, true) && $price >= 0 && $price <= 1000000000000 && mb_strlen($comment) <= 200, 'Invalid listing details.');
            $this->actions->ensure(AuctionListing::where('status', 'active')->count() < self::MAX_ACTIVE, 'The auction is full.');
            $this->actions->ensure(! AuctionListing::where('seller_id', $user->id)->where('created_at', '>', now()->subSeconds(self::LISTING_INTERVAL_SECONDS))->exists(), 'Wait 30 seconds between listings.');
            $item = InventoryItem::where('user_id', $user->id)->where('location', 'warehouse')->lockForUpdate()->findOrFail($inventoryId);
            $definition = $this->content->get('items', $item->item_id);
            $this->actions->ensure(in_array($definition['type'] ?? '', self::TYPES, true), 'This item cannot be auctioned.');
            $this->actions->ensure($quantity > 0 && $quantity <= $item->quantity, 'Invalid listing quantity.');
            $snapshot = ['item_id' => $item->item_id, 'quantity' => $quantity, 'refinement' => $item->refinement, 'enchantments' => $item->enchantments];
            $this->actions->money($user, -self::LISTING_FEE, $op, 'auction listing fee');
            if ($quantity === $item->quantity) {
                $item->location = 'auction';
                $item->save();
            } else {
                $item->quantity -= $quantity;
                $item->save();
                $item = InventoryItem::create($snapshot + ['user_id' => $user->id, 'location' => 'auction']);
            }
            $listing = AuctionListing::create(['seller_id' => $user->id, 'inventory_item_id' => $item->id, 'item_snapshot' => $snapshot, 'price' => $price, 'ends_at' => now()->addHours($hours), 'comment' => $comment]);
            $this->actions->ledger($user->id, $op, 'item_escrow', $quantity, 'auction listing', $item->item_id, ['auction_id' => $listing->id]);
            $this->event($listing, $user->id, 'listed', $price);

            return ['message' => 'Item listed.', 'auction_id' => $listing->id];
        });
    }

    public function bid(int $userId, string $key, int $auctionId, int $price): array
    {
        return $this->actions->execute($userId, 'auction.bid', $key, compact('auctionId', 'price'), function (User $user, int $op) use ($auctionId, $price) {
            $listing = AuctionListing::lockForUpdate()->findOrFail($auctionId);
            $this->actions->ensure($listing->status === 'active' && $listing->ends_at->isAfter(now()), 'This auction has ended.');
            $this->actions->ensure($this->isMember($user->id), 'Auction membership is required.');
            $this->actions->ensure($listing->seller_id !== $user->id && $listing->bidder_id !== $user->id, 'Seller and current high bidder cannot bid.');
            $this->actions->ensure($price >= self::minimumBid($listing->price) && $price <= 1000000000000, 'Bid is below the minimum or above the limit.');
            $this->actions->money($user, -$price, $op, 'auction bid escrow');
            if ($listing->bidder_id) {
                $previous = User::lockForUpdate()->findOrFail($listing->bidder_id);
                $this->actions->money($previous, $listing->escrow, $op, 'auction outbid refund');
                $this->event($listing, $previous->id, 'refunded', $listing->escrow);
            }
            $listing->price = $price;
            $listing->escrow = $price;
            $listing->bidder_id = $user->id;
            $listing->bid_count++;
            if ($listing->ends_at->lessThan(now()->addMinutes(self::EXTENSION_MINUTES))) {
                $listing->ends_at = now()->addMinutes(self::EXTENSION_MINUTES);
            }
            $listing->save();
            $this->event($listing, $user->id, 'bid', $price);

            return ['message' => 'Bid accepted.', 'auction_id' => $listing->id, 'price' => $price];
        });
    }

    public function settleDue(): int
    {
        return DB::transaction(function () {
            $this->actions->lock();

            return $this->settleDueLocked();
        }, 3);
    }

    private function settleDueLocked(): int
    {
        $count = 0;
        foreach (AuctionListing::where('status', 'active')->where('ends_at', '<=', now())->orderBy('id')->lockForUpdate()->get() as $listing) {
            $seller = User::lockForUpdate()->findOrFail($listing->seller_id);
            $item = InventoryItem::lockForUpdate()->findOrFail($listing->inventory_item_id);
            $this->actions->ensure($item->location === 'auction' && $item->user_id === $seller->id, 'Auction escrow is inconsistent.');
            $recipient = $listing->bidder_id ?? $listing->seller_id;
            if ($listing->bidder_id) {
                User::lockForUpdate()->findOrFail($recipient);
                $this->actions->money($seller, $listing->escrow, null, 'auction sold #'.$listing->id);
                $this->actions->ledger($seller->id, null, 'item', -$item->quantity, 'auction sold', $item->item_id, ['auction_id' => $listing->id]);
                $this->actions->ledger($recipient, null, 'item', $item->quantity, 'auction won', $item->item_id, ['auction_id' => $listing->id]);
            }
            $item->user_id = $recipient;
            $item->location = 'warehouse';
            $item->save();
            $listing->status = $listing->bidder_id ? 'sold' : 'unsold';
            $listing->escrow = 0;
            $listing->settled_at = now();
            $listing->save();
            $this->event($listing, $recipient, $listing->status, $listing->price);
            $count++;
        }

        return $count;
    }

    private function event(AuctionListing $listing, int $userId, string $kind, int $amount): void
    {
        AuctionEvent::create(['auction_listing_id' => $listing->id, 'user_id' => $userId, 'kind' => $kind, 'amount' => $amount]);
    }
}
