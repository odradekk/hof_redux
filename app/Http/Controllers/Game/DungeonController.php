<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Application\Dungeon\DungeonService;
use App\Application\Player\ItemDetails;
use App\Application\Player\Vitals;
use App\Domain\Combat\Fatigue;
use App\Domain\Content\ContentCatalog;
use App\Http\View\DungeonMapView;
use App\Http\View\ItemLines;
use App\Http\View\UnitCards;
use App\Models\Character;
use App\Models\DungeonRun;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class DungeonController
{
    public function __construct(private DungeonService $dungeons, private ContentCatalog $catalog, private ItemDetails $details) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $available = $this->dungeons->available($user);
        $dungeons = [];
        foreach ($this->dungeons->maps() as $id => $map) {
            $definition = $map->definition();
            $required = $definition['requires_item'] ?? null;
            $dungeons[] = ['id' => $id, 'name' => $map->name(), 'proper' => $definition['proper'] ?? '', 'summary' => $definition['summary'] ?? '',
                'rooms' => count($map->rooms()), 'open' => isset($available[$id]), 'href' => route('dungeons.prepare', $id),
                'requires' => $required === null ? null : $this->catalog->get('items', $required)['name']];
        }
        $runs = DungeonRun::where('user_id', $user->id)->where('status', '<>', 'active')->latest('id')->limit(10)->get()->map(fn (DungeonRun $run): array => [
            'name' => $this->dungeonName($run->dungeon_id), 'status' => __('hof.dungeon_status.'.$run->status), 'tone' => $run->status === 'cleared' ? 'support' : ($run->status === 'wiped' ? 'dmg' : 'meta'),
            'money' => $run->status === 'wiped' ? 0 : $run->loot_money, 'at' => $run->ended_at, 'href' => route('dungeons.run', $run->id),
        ])->all();

        return view('game.dungeon.index', ['dungeons' => $dungeons, 'runs' => $runs, 'active' => DungeonRun::activeFor($user->id) !== null]);
    }

    public function prepare(Request $request, string $dungeon): View|RedirectResponse
    {
        if (DungeonRun::activeFor($request->user()->id)) {
            return redirect()->route('dungeon');
        }
        $map = $this->dungeons->maps()[$dungeon] ?? abort(404);
        abort_unless(isset($this->dungeons->available($request->user())[$dungeon]), 403);
        $units = $request->user()->characters()->orderBy('id')->get()->map(function (Character $character): array {
            $vitals = Vitals::current($character);
            $unit = UnitCards::character($character->toArray(), $this->catalog->get('jobs', $character->job_id), $vitals);
            $unit['label'] .= ' · 负重 '.DungeonService::carryCapacity($character);

            return $unit;
        })->all();
        $consumables = InventoryItem::where('user_id', $request->user()->id)->where('location', 'warehouse')->orderBy('item_id')->orderBy('id')->get()
            ->filter(fn (InventoryItem $item): bool => isset($this->catalog->get('items', $item->item_id)['restore']))
            ->map(function (InventoryItem $item): array {
                $resolved = $this->details->resolve($item);

                return ['id' => $item->id, 'line' => ItemLines::fromInventory($item->toArray(), $resolved), 'weight' => (int) $resolved['handle'], 'quantity' => $item->quantity];
            })->values()->all();

        return view('game.dungeon.prepare', [
            'dungeon' => ['id' => $dungeon, 'name' => $map->name(), 'proper' => $map->definition()['proper'] ?? '', 'summary' => $map->definition()['summary'] ?? '', 'rooms' => count($map->rooms())],
            'units' => $units, 'selected' => $request->old('party', $request->user()->preferences['party'] ?? []), 'consumables' => $consumables,
            'rules' => ['move' => DungeonService::MOVE_STAMINA, 'battle' => DungeonService::BATTLE_STAMINA, 'carry' => DungeonService::CARRY_BASE, 'carry_str' => DungeonService::CARRY_STR_STEP],
        ]);
    }

    public function enter(Request $request, string $dungeon): RedirectResponse
    {
        $data = $request->validate([
            'operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct|min:1',
            'pack' => 'sometimes|array|max:100', 'pack.*.id' => 'required|integer|min:1', 'pack.*.quantity' => 'required|integer|between:0,999',
        ]);
        $pack = array_values(array_filter($data['pack'] ?? [], static fn (array $row): bool => (int) $row['quantity'] > 0));
        $result = $this->dungeons->enter($request->user()->id, $data['operation_id'], $dungeon, $data['party'], $pack);

        return redirect()->route('dungeon')->with('status', $result['message']);
    }

    public function run(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $run = DungeonRun::activeFor($user->id);
        if (! $run) {
            return redirect()->route('dungeons');
        }
        $map = $this->dungeons->map($run->dungeon_id);
        $room = $map->room($run->room);
        $state = $run->rooms[$run->room] ?? [];
        $members = Character::withoutGlobalScope('living')->where('user_id', $user->id)->whereIn('id', $run->party)->get()->keyBy('id');
        $party = [];
        foreach ($run->party as $id) {
            $member = $members[$id];
            $vitals = Vitals::current($member);
            $unit = UnitCards::character($member->toArray(), $this->catalog->get('jobs', $member->job_id), $member->died_at ? null : $vitals);
            $unit['href'] = route('player.character', $id);
            $penalty = Fatigue::penalty($vitals['stamina']);
            $party[] = ['unit' => $member->died_at ? array_diff_key($unit, ['href' => true, 'vitals' => true]) : $unit, 'fallen' => $member->died_at !== null, 'fatigue' => $member->died_at ? 0 : $penalty];
        }
        $living = array_values(array_filter($party, static fn (array $member): bool => ! $member['fallen']));
        $pack = InventoryItem::where('user_id', $user->id)->where('location', 'pack')->orderBy('item_id')->orderBy('id')->get()
            ->map(fn (InventoryItem $item): array => ['id' => $item->id, 'line' => ItemLines::fromInventory($item->toArray(), $this->details->resolve($item))])->all();
        $loot = InventoryItem::where('user_id', $user->id)->where('location', 'loot')->orderBy('item_id')->orderBy('id')->get()
            ->map(fn (InventoryItem $item): array => ItemLines::fromInventory($item->toArray(), $this->details->resolve($item)))->all();
        $exits = [];
        foreach ($map->neighbors($run->room) as $next) {
            $known = $run->rooms[$next]['visited'] ?? false;
            $exits[] = ['id' => $next, 'label' => $known ? $map->room($next)['name'] : '未探索的房间', 'kind' => $known ? DungeonMapView::TYPES[$map->room($next)['type']] : '？'];
        }
        $events = $run->events()->reorder('sequence', 'desc')->limit(30)->get()->map(static fn ($event): array => [
            'text' => $event->text, 'at' => $event->created_at, 'report' => $event->battle_report_id ? route('reports.show', $event->battle_report_id) : null,
        ])->all();

        return view('game.dungeon.run', [
            'name' => $map->name(), 'map' => DungeonMapView::build($map, $run->rooms, $run->room),
            'room' => [
                'name' => $room['name'], 'type' => $room['type'], 'kind' => DungeonMapView::TYPES[$room['type']], 'cleared' => $state['cleared'] ?? false,
                'text' => $room['text'] ?? null, 'choices' => array_column($room['choices'] ?? [], 'label'), 'open_stamina' => (int) ($room['open_stamina'] ?? 0),
                'uses' => $state['uses'] ?? ($room['uses'] ?? 0), 'heal' => (int) ($room['heal_percent'] ?? 0), 'stamina' => (int) ($room['stamina'] ?? 0),
            ],
            'exits' => $exits, 'party' => $party, 'living' => array_map(static fn (array $member): array => ['id' => $member['unit']['id'], 'name' => $member['unit']['name']], $living),
            'pack' => $pack, 'loot' => $loot, 'money' => $run->loot_money, 'events' => $events, 'steps' => $run->steps,
            'rules' => ['move' => DungeonService::MOVE_STAMINA, 'battle' => DungeonService::BATTLE_STAMINA],
        ]);
    }

    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate(['operation_id' => 'required|uuid', 'room' => 'required|string|max:32']);

        return $this->after($this->dungeons->move($request->user()->id, $data['operation_id'], $data['room']));
    }

    public function act(Request $request, string $action): RedirectResponse
    {
        $data = $request->validate([
            'operation_id' => 'required|uuid', 'choice' => 'sometimes|integer|between:0,3',
            'item' => 'sometimes|integer|min:1', 'character' => 'sometimes|integer|min:1',
            'confirm' => [Rule::requiredIf($action === 'retreat'), 'nullable', Rule::in(['撤离'])],
        ]);

        return $this->after($this->dungeons->act($request->user()->id, $data['operation_id'], $action, $data));
    }

    public function show(Request $request, DungeonRun $run): View
    {
        abort_unless($run->user_id === $request->user()->id, 404);
        $members = Character::withoutGlobalScope('living')->whereIn('id', $run->party)->get()->keyBy('id');
        $party = array_map(static fn (int $id): array => ['name' => $members[$id]->name ?? '—', 'fallen' => $members[$id]?->died_at !== null && $members[$id]->died_at->lessThanOrEqualTo($run->ended_at ?? now())], $run->party);
        $events = $run->events()->get()->map(static fn ($event): array => [
            'text' => $event->text, 'at' => $event->created_at, 'report' => $event->battle_report_id ? route('reports.show', $event->battle_report_id) : null,
        ])->all();

        return view('game.dungeon.show', ['name' => $this->dungeonName($run->dungeon_id), 'status' => __('hof.dungeon_status.'.$run->status), 'active' => $run->status === 'active',
            'money' => $run->status === 'wiped' ? 0 : $run->loot_money, 'steps' => $run->steps, 'party' => $party, 'events' => $events]);
    }

    private function after(array $result): RedirectResponse
    {
        if ($result['status'] !== 'active') {
            return redirect()->route('dungeons.run', $result['run_id'])->with('status', $result['message']);
        }

        return redirect()->route('dungeon')->with('status', $result['message']);
    }

    private function dungeonName(string $id): string
    {
        return isset($this->dungeons->maps()[$id]) ? $this->dungeons->map($id)->name() : $id;
    }
}
