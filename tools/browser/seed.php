<?php

declare(strict_types=1);

use App\Application\Battle\BattleService;
use App\Application\Multiplayer\BossService;
use App\Models\AdminAudit;
use App\Models\Announcement;
use App\Models\AuctionEvent;
use App\Models\AuctionListing;
use App\Models\BattleReport;
use App\Models\BoardMessage;
use App\Models\RankingEntry;
use App\Models\User;
use App\Services\CharacterFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/bootstrap.php';

// A mistyped target must never overwrite existing accounts, even in a local environment.
if (User::exists()) {
    throw new RuntimeException('Seed only a freshly migrated disposable database with zero users.');
}

$fixture = DB::transaction(function (): array {
    $password = Hash::make(BROWSER_PASSWORD);
    $makeUser = static function (string $login, string $name, bool $admin = false) use ($password): User {
        $user = new User;
        $user->forceFill([
            'login' => $login, 'name' => $name, 'password' => $password, 'is_admin' => $admin,
            'money' => 1000000,
            'preferences' => ['record_battle_log' => true, 'no_js_inventory' => false, 'color' => '99ccff'],
            'created_at' => now()->subDays(7),
        ])->save();

        return $user;
    };
    $factory = app(CharacterFactory::class);
    $owner = $makeUser(BROWSER_LOGIN, '视觉验收队伍', true);
    $party = [];
    foreach ([1, 2, 3, 4, 1] as $index => $type) {
        $character = $factory->create($owner, $type, ['前卫剑士', '魔法学徒', '弓箭手', '坚守护卫', '后卫战士'][$index], $index % 2);
        $character->update(['stat_points' => 5, 'skill_points' => 5, 'position' => $index < 3 ? 'front' : 'back']);
        $party[] = $character->id;
    }
    $owner->update(['preferences' => [...$owner->preferences, 'party' => $party]]);
    foreach (['1000' => 3, '1001' => 2, '6001' => 40, '6002' => 20, '9000' => 1] as $item => $quantity) {
        $owner->inventory()->create(['item_id' => (string) $item, 'quantity' => $quantity, 'location' => 'warehouse']);
    }

    // More than 30 accounts also exercises the actual administrator pagination template.
    $peers = [];
    for ($index = 1; $index <= 30; $index++) {
        $peer = $makeUser(sprintf('hof_qa_%02d', $index), sprintf('远征队伍%02d', $index));
        $character = $factory->create($peer, ($index % 4) + 1, sprintf('勇者%02d', $index), $index % 2);
        RankingEntry::create([
            'user_id' => $peer->id, 'position' => $index, 'party' => [$character->id],
            'party_set_at' => now()->subDays(3), 'wins' => 32 - $index, 'losses' => $index, 'draws' => 1, 'defenses' => 2,
        ]);
        $peers[] = $peer;
    }
    RankingEntry::create(['user_id' => $owner->id, 'position' => 31, 'party' => $party, 'party_set_at' => now()->subDays(3), 'wins' => 4, 'losses' => 2]);

    $listings = [];
    foreach (['1000', '1001', '6001'] as $index => $itemId) {
        $seller = $peers[$index];
        $snapshot = ['item_id' => $itemId, 'quantity' => $index + 1, 'refinement' => 0, 'enchantments' => []];
        $item = $seller->inventory()->create($snapshot + ['location' => 'auction']);
        $listing = AuctionListing::create([
            'seller_id' => $seller->id, 'inventory_item_id' => $item->id, 'item_snapshot' => $snapshot,
            'price' => 1000 + 500 * $index, 'ends_at' => now()->addHours(6 + $index),
            'comment' => '合成测试物品，用于检查中文换行、物品说明与出价表单。',
        ]);
        AuctionEvent::create(['auction_listing_id' => $listing->id, 'user_id' => $seller->id, 'kind' => 'listed', 'amount' => $listing->price]);
        $listings[] = $listing->id;
    }
    Announcement::create(['title' => '测试世界公告', 'body' => "这是可丢弃的视觉验收环境。\n请检查键盘导航、窄屏换行和各项原生表单。", 'published' => true]);
    BoardMessage::create(['user_id' => $peers[0]->id, 'author_name' => $peers[0]->name, 'author_color' => '99ccff', 'body' => '出发前记得设置行动模式。队伍准备好了！']);
    BoardMessage::create(['user_id' => $peers[1]->id, 'author_name' => $peers[1]->name, 'author_color' => '003366', 'body' => '深色自定义署名也需要保持可读。']);
    AdminAudit::create(['admin_id' => $owner->id, 'action' => 'account.balance', 'target' => (string) $peers[0]->id, 'details' => ['delta' => 0, 'reason' => 'Synthetic browser fixture, no real account changes.']]);
    app(BossService::class)->bootstrap();

    // Fixed combat seed and character order keep the visual report reproducible.
    $battle = app(BattleService::class)->simulateParties($owner, $party, $owner, $party, 'simulation', 20261005, 10);
    $report = BattleReport::create(['user_id' => $owner->id, 'mode' => 'simulation', 'public' => false, 'report' => $battle]);

    return ['schema' => 1, 'userId' => $owner->id, 'characterIds' => $party, 'auctionIds' => $listings, 'battleReportId' => $report->id];
});

echo json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
