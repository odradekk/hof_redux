<?php

namespace App\Application\Community;

use App\Application\Multiplayer\RankingService;
use App\Application\Support\GameAction;
use App\Models\AdminAudit;
use App\Models\AuctionListing;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountDeletion
{
    public function __construct(private GameAction $actions, private RankingService $ranking) {}

    public function delete(int $userId, ?int $administratorId = null): void
    {
        DB::transaction(function () use ($userId, $administratorId) {
            $this->actions->lock();
            $user = User::lockForUpdate()->findOrFail($userId);
            $this->actions->ensure(! AuctionListing::where('status', 'active')->where(fn ($q) => $q->where('seller_id', $userId)->orWhere('bidder_id', $userId))->exists(), 'Settle active auction listings and bids before deleting this account.');
            if ($administratorId !== null) {
                $admin = User::findOrFail($administratorId);
                abort_unless($admin->is_admin, 403);
                $this->actions->ensure($administratorId !== $userId, 'Administrators cannot delete themselves from this console.');
            }
            AdminAudit::create(['admin_id' => $administratorId, 'action' => 'account.delete', 'target' => (string) $userId, 'details' => ['login' => $user->login, 'self' => $administratorId === null]]);
            // Equipment references must be removed before cascading character deletion.
            InventoryItem::where('user_id', $userId)->delete();
            DB::table('sessions')->where('user_id', $userId)->delete();
            $user->delete();
            // Commit the vacated slot with deletion, even if the next challenge is rejected.
            $this->ranking->repackLocked();
        }, 3);
    }
}
