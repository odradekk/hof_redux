<?php

namespace App\Http\Controllers\Game;

use App\Application\Battle\BattleService;
use App\Application\Multiplayer\AuctionService;
use App\Application\Multiplayer\BossService;
use App\Application\Multiplayer\RankingService;
use App\Application\Player\ItemDetails;
use App\Domain\Content\ContentCatalog;
use App\Http\View\ItemLines;
use App\Http\View\UnitCards;
use App\Models\AuctionEvent;
use App\Models\AuctionListing;
use App\Models\BossChallenge;
use App\Models\InventoryItem;
use App\Models\RankingChallenge;
use App\Models\RankingEntry;
use App\Models\User;
use Illuminate\Http\Request;

final class MultiplayerController
{
    public function __construct(private AuctionService $auctions, private RankingService $ranking, private BossService $bosses, private ContentCatalog $content, private BattleService $battles) {}

    public function auction(Request $request, ItemDetails $details)
    {
        $this->auctions->settleDue();
        $sorts = ['time' => ['ends_at', 'asc'], 'price' => ['price', 'desc'], 'rprice' => ['price', 'asc'], 'bid' => ['bid_count', 'desc'], 'no' => ['id', 'asc']];
        $sort = is_string($request->query('sort')) && isset($sorts[$request->query('sort')]) ? $request->query('sort') : 'time';
        [$column, $direction] = $sorts[$sort];
        $mine = $request->boolean('mine');
        $query = AuctionListing::where('status', 'active');
        if ($mine) {
            $query->where('seller_id', $request->user()->id);
        }
        $members = $this->auctions->isMember($request->user()->id);
        $names = User::pluck('name', 'id');
        $listings = $query->orderBy($column, $direction)->orderBy('id')->get()->map(function (AuctionListing $listing) use ($details, $members, $request, $names): array {
            $row = $listing->item_snapshot;

            return [
                'id' => $listing->id, 'line' => ItemLines::fromInventory($row, $details->resolve($row)),
                'price' => $listing->price, 'minimum_bid' => AuctionService::minimumBid($listing->price),
                'comment' => $listing->comment, 'ends_at' => $listing->ends_at,
                'ending_soon' => $listing->ends_at->lessThan(now()->addHour()),
                'bid_count' => $listing->bid_count, 'bidder' => $names[$listing->bidder_id] ?? '—',
                'seller' => $names[$listing->seller_id] ?? '—',
                'can_bid' => $members && $listing->seller_id !== $request->user()->id && $listing->bidder_id !== $request->user()->id,
            ];
        })->all();
        $items = InventoryItem::where('user_id', $request->user()->id)->where('location', 'backpack')->get()
            ->filter(fn (InventoryItem $item): bool => in_array($this->content->get('items', $item->item_id)['type'] ?? '', AuctionService::TYPES, true))
            ->map(static function (InventoryItem $item) use ($details): array {
                $row = $item->toArray();

                return ['id' => $item->id, 'line' => ItemLines::fromInventory($row, $details->resolve($row))];
            })->all();
        $eventNames = ['listed' => '出品', 'bid' => '出价', 'refunded' => '退还出价', 'sold' => '成交', 'unsold' => '流拍'];
        $events = AuctionEvent::latest('id')->limit(100)->get()->map(static fn (AuctionEvent $event): array => [
            'who' => $names[$event->user_id] ?? '—',
            'text' => 'No.'.$event->auction_listing_id.' '.($eventNames[$event->kind] ?? '拍卖更新').' · $ '.number_format($event->amount),
            'at' => $event->created_at,
        ])->all();
        $sortLinks = [];
        foreach ($sorts as $key => [$field, $order]) {
            $sortLinks[$key] = [
                'href' => route('auction', array_filter(['sort' => $key, 'mine' => $mine ? 1 : null])),
                'active' => $sort === $key, 'arrow' => $sort === $key ? ($order === 'asc' ? ' ▲' : ' ▼') : '',
            ];
        }
        $tabs = [['label' => '拍卖列表', 'href' => '#auction-list', 'active' => true], ['label' => '回顾记录', 'href' => '#auction-log', 'active' => false]];
        if ($members) {
            $tabs[] = ['label' => '出品', 'href' => '#auction-new', 'active' => false];
        }

        return view('game.auction', compact('listings', 'items', 'events', 'members', 'sortLinks', 'sort', 'mine', 'tabs'));
    }

    public function join(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid']);

        $this->auctions->join($request->user()->id, $data['operation_id']);

        return redirect()->route('auction')->with('status', '已购买拍卖会员卡。');
    }

    public function exhibit(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'inventory_id' => 'required|integer|min:1', 'quantity' => 'required|integer|min:1|max:1000000', 'price' => 'required|integer|min:0|max:1000000000000', 'hours' => 'required|integer|in:1,3,6,12,18,24', 'comment' => 'nullable|string|max:200']);
        $result = $this->auctions->exhibit($request->user()->id, $data['operation_id'], (int) $data['inventory_id'], (int) $data['quantity'], (int) $data['price'], (int) $data['hours'], $data['comment'] ?? '');

        return redirect()->route('auction')->with('status', '物品已上架。');
    }

    public function bid(Request $request, int $auction)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'price' => 'required|integer|min:1|max:1000000000000']);
        $result = $this->auctions->bid($request->user()->id, $data['operation_id'], $auction, (int) $data['price']);

        return redirect()->route('auction')->with('status', '出价成功。');
    }

    public function ranking(Request $request)
    {
        $names = User::pluck('name', 'id');
        $team = $request->user() ? RankingEntry::where('user_id', $request->user()->id)->first() : null;
        $entries = RankingEntry::whereNotNull('position')->orderBy('position')->get();
        $lastPlace = $entries->isEmpty() ? 0 : RankingService::place($entries->last()->position);
        $groups = $entries->groupBy(static fn (RankingEntry $entry): int => RankingService::place($entry->position))->map(function ($rows, $place) use ($names, $request, $lastPlace): array {
            return [
                'place' => $place, 'label' => $place === $lastPlace && $place > 3 ? '底' : $place.'位',
                'crown' => $place <= 3 ? 'image/icon/crown0'.$place.'.png' : null,
                'entries' => $rows->map(static function (RankingEntry $entry) use ($names, $request): array {
                    $total = $entry->wins + $entry->losses + $entry->draws;
                    $rate = $total > 0 ? (string) round(100 * $entry->wins / $total) : '--';

                    return [
                        'name' => $names[$entry->user_id] ?? '—', 'own' => $request->user()?->id === $entry->user_id,
                        'record' => "({$total}战 {$entry->wins}胜{$entry->losses}败 {$entry->draws}引 {$entry->defenses}防 胜率{$rate}%)",
                    ];
                })->all(),
            ];
        });
        $ownPlace = $team?->position ? RankingService::place($team->position) : null;
        $nearStart = max(1, min(max(1, $lastPlace - 4), ($ownPlace ?? $lastPlace) - 2));
        $rankings = [
            ['label' => '排行榜(RANKING)', 'rows' => $groups->take(5)->all()],
            ['label' => '附近排名(Nearly)', 'rows' => $groups->filter(static fn (array $group): bool => $group['place'] >= $nearStart && $group['place'] < $nearStart + 5)->all()],
        ];
        $units = $request->user()?->characters->map(fn ($character): array => UnitCards::character($character->toArray(), $this->content->get('jobs', $character->job_id)))->all() ?? [];
        $selected = $team?->party ?? [];
        $teamReadyAt = $team?->party_set_at?->addHours(48);
        $canRegister = ! $teamReadyAt || $teamReadyAt->lessThanOrEqualTo(now());
        $challengeAt = $team?->challenge_at;
        $canChallenge = $team && $ownPlace !== 1 && (! $challengeAt || $challengeAt->lessThanOrEqualTo(now()));
        $resultNames = ['challenger_win' => '挑战方获胜', 'defender_win' => '防守方获胜', 'defender_no_party' => '对手无可用队伍', 'draw' => '平局'];
        $challenges = RankingChallenge::latest('id')->limit(30)->get()->map(static fn (RankingChallenge $challenge): array => [
            'who' => $names[$challenge->challenger_id] ?? '—',
            'text' => '对阵 '.($names[$challenge->defender_id] ?? '—').' · '.($resultNames[$challenge->result] ?? '挑战结束'),
            'at' => $challenge->created_at, 'href' => $challenge->report ? route('reports.ranking', $challenge->id) : null,
        ])->all();

        return view('game.ranking', compact('rankings', 'team', 'units', 'selected', 'teamReadyAt', 'canRegister', 'challengeAt', 'canChallenge', 'ownPlace', 'challenges'));
    }

    public function rankTeam(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct']);
        $result = $this->ranking->register($request->user()->id, $data['operation_id'], $data['party']);

        return redirect()->route('ranking')->with('status', '竞技队伍已登记。');
    }

    public function rankChallenge(Request $request)
    {
        $data = $request->validate(['operation_id' => 'required|uuid']);
        $result = $this->ranking->challenge($request->user()->id, $data['operation_id']);

        $message = match ($result['result'] ?? '') {
            'challenger_win' => '挑战获胜。', 'defender_win' => '防守方获胜。',
            'draw' => '挑战以平局结束。', 'defender_no_party' => '对手没有可用队伍，您已晋级。',
            default => '您已成为竞技场第一名。',
        };

        return redirect()->route('ranking')->with('status', $message);
    }

    public function bosses(Request $request, ?int $boss = null)
    {
        $this->bosses->respawnDue();
        $bosses = $this->bosses->summaries();
        if ($boss !== null) {
            $bosses = array_values(array_filter($bosses, static fn (array $summary): bool => $summary['id'] === $boss));
            abort_if($bosses === [], 404);
        }
        $bosses = array_map(static fn (array $summary): array => $summary + ['unit' => UnitCards::boss($summary)], $bosses);
        $units = $request->user()->characters->map(fn ($character): array => UnitCards::character($character->toArray(), $this->content->get('jobs', $character->job_id)))->all();
        $selected = $request->user()->preferences['party'] ?? [];
        $latest = BossChallenge::where('user_id', $request->user()->id)->latest('created_at')->first(['created_at']);
        $readyAt = $latest?->created_at->copy()->addMinutes(20);
        $ready = ! $readyAt || $readyAt->lessThanOrEqualTo(now());
        $challenges = BossChallenge::where('user_id', $request->user()->id)->latest('id')->limit(20)->get(['id', 'killed', 'created_at'])->map(static fn (BossChallenge $challenge): array => [
            'who' => '', 'text' => $challenge->killed ? '击败首领' : '挑战首领',
            'at' => $challenge->created_at, 'href' => route('multiplayer.report', ['boss', $challenge->id]),
        ])->all();

        return view('game.boss', compact('bosses', 'boss', 'units', 'selected', 'readyAt', 'ready', 'challenges'));
    }

    public function bossChallenge(Request $request, int $boss)
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct']);
        $result = $this->bosses->challenge($request->user()->id, $data['operation_id'], $boss, $data['party']);

        return redirect()->route('bosses')->with('status', $result['killed'] ? '共享首领已被击败。' : '共享首领挑战结束。');
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
