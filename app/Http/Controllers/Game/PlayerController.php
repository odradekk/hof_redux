<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Application\Player\ItemDetails;
use App\Application\Player\PlayerRules;
use App\Application\Player\PlayerService;
use App\Domain\Combat\SnapshotFactory;
use App\Domain\Content\ContentCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PlayerController
{
    public function __construct(private ContentCatalog $catalog, private ItemDetails $details, private PlayerService $players) {}

    public function roster(Request $request): View
    {
        return view('game.player', ['characters' => $request->user()->characters()->with('equipment')->orderBy('id')->get(),
            'catalog' => $this->catalog, 'prices' => PlayerRules::RECRUIT_PRICES]);
    }

    public function character(Request $request, int $id): View
    {
        $character = $request->user()->characters()->with('equipment')->findOrFail($id);
        $equipment = $character->equipment->map(fn ($row) => ['row' => $row, 'data' => $this->details->resolve($row)]);
        $inventory = $request->user()->inventory()->where('location', 'backpack')->orderBy('item_id')->get()
            ->map(fn ($row) => ['row' => $row, 'data' => $this->details->resolve($row)]);
        $job = $this->catalog->get('jobs', $character->job_id);
        $this->players->refreshVitals($character);
        $passives = [];
        foreach ($character->skills as $skillId) {
            $skill = $this->catalog->get('skills', $skillId);
            if ($skill['passive'] ?? false) {
                $passives[] = $skill;
            }
        }
        $resolvedEquipment = [];
        foreach ($equipment as $entry) {
            $resolvedEquipment[$entry['row']->slot] = $entry['data'];
        }
        $effective = SnapshotFactory::character($character->stats, $resolvedEquipment, $passives);
        $availableSkills = $this->catalog->availableSkills($character->job_id, $character->level, $character->skills);
        $jobs = array_filter($this->catalog->all('class_changes'), fn ($rule) => $rule['from_job'] === $character->job_id && $character->level >= $rule['minimum_level']);
        $conditions = $this->catalog->selectableConditions();
        $tactics = $character->tactics;
        $maxPatterns = PlayerRules::maxPatterns((int) $character->stats['int'], $character->level);
        while (count($tactics) < $maxPatterns) {
            $tactics[] = PlayerRules::defaultTactic($character);
        }

        return view('game.player-character', compact('character', 'equipment', 'inventory', 'job', 'availableSkills', 'jobs', 'conditions', 'tactics', 'maxPatterns', 'effective') + ['catalog' => $this->catalog]);
    }

    public function inventory(Request $request): View
    {
        return view('game.inventory', $this->inventoryData($request));
    }

    public function shop(Request $request): View
    {
        $stock = [];
        foreach ($this->catalog->get('economy_rules', 'shop')['values'] as $id) {
            $stock[(string) $id] = $this->details->resolve(['item_id' => (string) $id]);
        }

        return view('game.shop', $this->inventoryData($request) + ['stock' => $stock]);
    }

    public function crafting(Request $request): View
    {
        $recipes = $this->catalog->all('recipes');

        return view('game.crafting', $this->inventoryData($request) + ['recipes' => $recipes, 'catalog' => $this->catalog]);
    }

    public function preferences(Request $request): View
    {
        return view('game.player-preferences', ['preferences' => $request->user()->preferences ?? []]);
    }

    private function inventoryData(Request $request): array
    {
        $type = $request->validate(['type' => ['nullable', 'string', 'max:30']])['type'] ?? null;
        $rows = $request->user()->inventory()->where('location', 'backpack')->orderBy('item_id')->orderBy('id')->get()
            ->map(fn ($row) => ['row' => $row, 'data' => $this->details->resolve($row)]);
        $types = $rows->pluck('data.type')->unique()->sort()->values();
        $items = $type ? $rows->filter(fn ($entry) => $entry['data']['type'] === $type) : $rows;

        return compact('items', 'types', 'type') + ['noJs' => (bool) ($request->user()->preferences['no_js_inventory'] ?? false)];
    }

    public function command(Request $request, string $command): RedirectResponse
    {
        $key = $request->validate(['operation_id' => ['required', 'uuid']])['operation_id'];
        $input = $request->except(['_token', 'operation_id']);
        if (in_array($command, ['buy', 'sell'], true) && is_array($input['items'] ?? null)) {
            // Unselected form rows are zero; negative and malformed quantities still fail validation.
            $input['items'] = array_values(array_filter($input['items'], fn ($row) => ! is_array($row) || ! isset($row['quantity']) || (string) $row['quantity'] !== '0'));
        }
        $result = $this->players->execute($request->user()->id, $command, $key, $input);
        $redirect = match ($command) {
            'recruit', 'dismiss', 'party' => route('player.roster'),
            'buy', 'sell', 'work' => route('player.shop'),
            'craft', 'refine' => route('player.crafting'),
            'preferences', 'team-name' => route('player.preferences'),
            default => route('player.character', ['id' => $result['character_id']]),
        };

        return redirect($redirect)->with('status', $result['message'])->with('player_result', $result);
    }
}
