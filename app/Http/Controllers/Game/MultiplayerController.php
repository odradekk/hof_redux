<?php

namespace App\Http\Controllers\Game;

use App\Application\Battle\BattleService;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use App\Domain\Content\ContentCatalog;
use App\Models\AuctionEvent;
use App\Models\AuctionListing;
use App\Models\BossChallenge;
use App\Models\BossInstance;
use App\Models\InventoryItem;
use App\Models\RankingChallenge;
use App\Models\RankingEntry;
use App\Models\User;
use Illuminate\Http\Request;

final class MultiplayerController
{
    public function __construct(private AuctionService $auctions, private RankingService $ranking, private BossService $bosses, private ContentCatalog $content, private BattleService $battles) {}

    public function auction(Request $request)
    {
        $this->auctions->settleDue();
        $sorts = ['time' => ['ends_at', 'asc'], 'price' => ['price', 'desc'], 'rprice' => ['price', 'asc'], 'bid' => ['bid_count', 'desc'], 'no' => ['id', 'asc']];
        [$column,$direction] = $sorts[$request->query('sort', 'time')] ?? $sorts['time'];
        $query = AuctionListing::where('status', 'active');
        if ($request->query('mine')) {
            $query->where('seller_id', $request->user()->id);
        }
        $items = InventoryItem::where('user_id', $request->user()->id)->where('location', 'backpack')->get();

        return view('game.auction', ['listings' => $query->orderBy($column, $direction)->get(), 'items' => $items, 'events' => AuctionEvent::latest('id')->limit(100)->get(), 'members' => $this->auctions->isMember($request->user()->id), 'names' => User::pluck('name', 'id'), 'catalog' => $this->content]);
    }

    public function join(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid']);

        return redirect()->route('auction')->with('status', $this->auctions->join($request->user()->id, $data['operation_id'])['message']);
    }

    public function exhibit(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'inventory_id' => 'required|integer|min:1', 'quantity' => 'required|integer|min:1|max:1000000', 'price' => 'required|integer|min:0|max:1000000000000', 'hours' => 'required|integer|in:1,3,6,12,18,24', 'comment' => 'nullable|string|max:200']);
        $result = $this->auctions->exhibit($request->user()->id, $data['operation_id'], (int) $data['inventory_id'], (int) $data['quantity'], (int) $data['price'], (int) $data['hours'], $data['comment'] ?? '');

        return redirect()->route('auction')->with('status', $result['message']);
    }

    public function bid(Request $request, int $auction)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'price' => 'required|integer|min:1|max:1000000000000']);
        $result = $this->auctions->bid($request->user()->id, $data['operation_id'], $auction, (int) $data['price']);

        return redirect()->route('auction')->with('status', $result['message']);
    }

    public function ranking(Request $request)
    {
        return view('game.ranking', ['entries' => RankingEntry::whereNotNull('position')->orderBy('position')->get(), 'names' => User::pluck('name', 'id'), 'team' => $request->user() ? RankingEntry::where('user_id', $request->user()->id)->first() : null, 'characters' => $request->user()?->characters ?? collect(), 'challenges' => RankingChallenge::latest('id')->limit(30)->get()]);
    }

    public function rankTeam(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct']);
        $result = $this->ranking->register($request->user()->id, $data['operation_id'], $data['party']);

        return redirect()->route('ranking')->with('status', $result['message']);
    }

    public function rankChallenge(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid']);
        $result = $this->ranking->challenge($request->user()->id, $data['operation_id']);

        return redirect()->route('ranking')->with('status', $result['message'].' '.($result['result'] ?? ''));
    }

    public function bosses(Request $request)
    {
        $this->bosses->respawnDue();
        // Deliberately project display fields: HP/SP never enter the rendered page.
        $bosses = BossInstance::orderBy('id')->get()->map(fn ($boss) => ['id' => $boss->id, 'name' => $boss->definition['UnionName'], 'limit' => $boss->definition['LevelLimit'], 'alive' => $boss->hp > 0, 'respawns_at' => $boss->respawns_at]);

        return view('game.boss', ['bosses' => $bosses, 'characters' => $request->user()->characters, 'challenges' => BossChallenge::where('user_id', $request->user()->id)->latest('id')->limit(20)->get(['id', 'killed', 'created_at'])]);
    }

    public function bossChallenge(Request $request, int $boss)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct']);
        $result = $this->bosses->challenge($request->user()->id, $data['operation_id'], $boss, $data['party']);

        return redirect()->route('bosses')->with('status', $result['message']);
    }

    public function report(Request $request, string $kind, int $report)
    {
        $row = $kind === 'ranking' ? RankingChallenge::findOrFail($report) : BossChallenge::findOrFail($report);
        if ($kind === 'boss') {
            abort_unless($row->user_id === $request->user()->id, 403);
        }
        $data = $row->report ?? ['winner' => null, 'events' => [], 'teams' => [[], []], 'reason' => $row->result ?? 'No battle'];

        return view('game.battle.show', ['report' => $this->battles->publicReport($data)]);
    }
}
