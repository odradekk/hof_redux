<?php

namespace Tests\Feature\Multiplayer;

use App\Application\Multiplayer\AuctionService;
use App\Application\Support\GameAction;
use App\Models\AuctionListing;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuctionTest extends TestCase
{
    use RefreshDatabase;

    private function player(string $login, int $money = 100000): User
    {
        $user = User::create(['login' => $login, 'name' => $login, 'password' => 'test-password']);
        $user->money = $money;
        $user->save();
        InventoryItem::create(['user_id' => $user->id, 'item_id' => '9000', 'quantity' => 1, 'location' => 'warehouse']);

        return $user;
    }

    private function listing(User $user): array
    {
        $item = InventoryItem::create(['user_id' => $user->id, 'item_id' => '1000', 'quantity' => 2, 'location' => 'warehouse']);
        $result = app(AuctionService::class)->exhibit($user->id, (string) Str::uuid(), $item->id, 1, 100, 1);

        return [$item, AuctionListing::findOrFail($result['auction_id'])];
    }

    public function test_bid_refund_and_sold_settlement_conserve_assets_exactly_once(): void
    {
        $seller = $this->player('seller');
        $a = $this->player('buyer_a');
        $b = $this->player('buyer_b');
        [$item,$listing] = $this->listing($seller);
        $service = app(AuctionService::class);
        $key = (string) Str::uuid();
        $first = $service->bid($a->id, $key, $listing->id, 200);
        self::assertSame($first, $service->bid($a->id, $key, $listing->id, 200));
        $service->bid($b->id, (string) Str::uuid(), $listing->id, 300);
        self::assertSame(100000, $a->fresh()->money);
        self::assertSame(99700, $b->fresh()->money);
        $this->travel(2)->hours();
        self::assertSame(1, $service->settleDue());
        self::assertSame(0, $service->settleDue());
        self::assertSame(99800, $seller->fresh()->money);
        self::assertSame('sold', $listing->fresh()->status);
        self::assertSame(0, $listing->fresh()->escrow);
        self::assertSame(1, InventoryItem::where('user_id', $b->id)->where('item_id', '1000')->sum('quantity'));
        self::assertSame(2, InventoryItem::where('item_id', '1000')->sum('quantity'));
        self::assertSame(299500, (int) User::sum('money'));
    }

    public function test_unsold_returns_item_and_does_not_refund_listing_fee(): void
    {
        $seller = $this->player('seller');
        [, $listing] = $this->listing($seller);
        $this->travel(1)->hours();
        app(AuctionService::class)->settleDue();
        self::assertSame('unsold', $listing->fresh()->status);
        self::assertSame(99500, $seller->fresh()->money);
        self::assertSame(2, InventoryItem::where('user_id', $seller->id)->where('item_id', '1000')->where('location', 'warehouse')->sum('quantity'));
    }

    public function test_late_bid_extends_deadline_and_exact_expiry_rejects_bid_without_debit(): void
    {
        $seller = $this->player('seller');
        $buyer = $this->player('buyer');
        [, $listing] = $this->listing($seller);
        $this->travel(55)->minutes();
        app(AuctionService::class)->bid($buyer->id, (string) Str::uuid(), $listing->id, 200);
        self::assertSame(now()->addMinutes(15)->getTimestamp(), $listing->fresh()->ends_at->getTimestamp());
        $this->travel(15)->minutes();
        try {
            app(AuctionService::class)->bid($seller->id, (string) Str::uuid(), $listing->id, 300);
            self::fail('Expired bid accepted');
        } catch (ValidationException) {
        }
        self::assertSame(99500, $seller->fresh()->money);
    }

    public function test_failed_listing_rolls_back_fee_and_operation(): void
    {
        $seller = $this->player('seller');
        $key = (string) Str::uuid();
        $card = InventoryItem::where('user_id', $seller->id)->firstOrFail();
        try {
            app(AuctionService::class)->exhibit($seller->id, $key, $card->id, 1, 100, 1);
            self::fail('Membership card listed');
        } catch (ValidationException) {
        }
        self::assertSame(100000, $seller->fresh()->money);
        self::assertSame(0, DB::table('operations')->count());
        self::assertSame('warehouse', $card->fresh()->location);
    }

    public function test_idempotency_key_cannot_be_reused_for_different_payload(): void
    {
        $user = $this->player('seller');
        $key = (string) Str::uuid();
        $actions = app(GameAction::class);
        $actions->execute($user->id, 'test', $key, ['a' => 1], fn () => ['ok' => true]);
        $this->expectException(ValidationException::class);
        $actions->execute($user->id, 'test', $key, ['a' => 2], fn () => ['ok' => true]);
    }

    public function test_membership_cost_and_retry(): void
    {
        $user = $this->player('seller');
        InventoryItem::where('user_id', $user->id)->delete();
        $key = (string) Str::uuid();
        $service = app(AuctionService::class);
        $service->join($user->id, $key);
        $service->join($user->id, $key);
        self::assertSame(89000, $user->fresh()->money);
        self::assertSame(1, InventoryItem::where('user_id', $user->id)->where('item_id', '9000')->sum('quantity'));
    }

    public function test_minimum_bid_boundaries(): void
    {
        self::assertSame(100, AuctionService::minimumBid(0));
        self::assertSame(1100, AuctionService::minimumBid(1000));
        self::assertSame(1111, AuctionService::minimumBid(1010));
    }

    public function test_entire_unsold_item_can_be_relisted_without_losing_history(): void
    {
        $seller = $this->player('seller');
        [, $first] = $this->listing($seller);
        $service = app(AuctionService::class);
        $this->travel(1)->hours();
        $service->settleDue();
        $inventoryId = $first->inventory_item_id;
        $result = $service->exhibit($seller->id, (string) Str::uuid(), $inventoryId, 1, 100, 1);
        $second = AuctionListing::findOrFail($result['auction_id']);
        self::assertSame('unsold', $first->fresh()->status);
        self::assertSame('active', $second->status);
        self::assertSame($inventoryId, $second->inventory_item_id);
        self::assertSame($inventoryId, $first->fresh()->inventory_item_id);
        self::assertSame(99000, $seller->fresh()->money);
        self::assertSame(2, InventoryItem::where('item_id', '1000')->sum('quantity'));
    }

    public function test_entire_won_item_can_be_relisted_by_new_owner(): void
    {
        $seller = $this->player('seller');
        $buyer = $this->player('buyer');
        [, $first] = $this->listing($seller);
        $service = app(AuctionService::class);
        $service->bid($buyer->id, (string) Str::uuid(), $first->id, 200);
        $this->travel(1)->hours();
        $service->settleDue();
        $inventoryId = $first->inventory_item_id;
        $result = $service->exhibit($buyer->id, (string) Str::uuid(), $inventoryId, 1, 200, 1);
        $second = AuctionListing::findOrFail($result['auction_id']);
        self::assertSame('sold', $first->fresh()->status);
        self::assertSame('active', $second->status);
        self::assertSame($buyer->id, $second->seller_id);
        self::assertSame($inventoryId, $second->inventory_item_id);
        self::assertSame($inventoryId, $first->fresh()->inventory_item_id);
        self::assertSame(99300, $buyer->fresh()->money);
        self::assertSame(2, InventoryItem::where('item_id', '1000')->sum('quantity'));
    }

    public function test_database_still_rejects_two_active_listings_for_one_item(): void
    {
        $seller = $this->player('seller');
        [, $first] = $this->listing($seller);
        $duplicate = $first->replicate();
        $this->expectException(QueryException::class);
        $duplicate->save();
    }
}
