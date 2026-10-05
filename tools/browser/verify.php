<?php

declare(strict_types=1);

use App\Models\AuctionListing;
use App\Models\BattleReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message.PHP_EOL;
};
$user = User::where('login', BROWSER_LOGIN)->firstOrFail();
$check($user->is_admin && $user->characters()->count() === 5, 'synthetic five-character account remains intact');
foreach (['player.buy', 'auction.bid', 'world.hunt', 'player.tactics'] as $command) {
    $check(DB::table('operations')->where('user_id', $user->id)->where('command', $command)->count() === 1, $command.' persisted exactly once after native submit and HTTP replay');
}
$entries = DB::table('asset_entries')->where('user_id', $user->id);
$check((clone $entries)->where('reason', 'shop buy')->where('kind', 'money')->count() === 1, 'purchase charged once');
$check((int) (clone $entries)->where('reason', 'shop buy')->where('kind', 'money')->sum('amount') === -6000, 'purchase charged the server price for exactly two swords');
$check((int) (clone $entries)->where('reason', 'shop buy')->where('kind', 'item')->where('item_id', '1002')->sum('amount') === 2, 'purchase delivered exactly two swords');
$check((clone $entries)->where('reason', 'auction bid escrow')->where('kind', 'money')->count() === 1, 'auction escrow charged once');
$check((int) (clone $entries)->where('reason', 'ordinary hunt')->where('kind', 'stamina')->sum('amount') === -1, 'hunt consumed exactly one stamina');
$check(BattleReport::where('user_id', $user->id)->where('mode', 'pve')->count() === 1, 'hunt produced exactly one report');
$auction = AuctionListing::where('bidder_id', $user->id)->firstOrFail();
$check($auction->bid_count === 1 && $auction->price === 1100 && $auction->escrow === 1100, 'auction has one bid with matching escrow');
$check($user->characters()->orderBy('id')->first()->tactics[0]['quantity'] === 25, 'native tactics form saved the requested quantity');
