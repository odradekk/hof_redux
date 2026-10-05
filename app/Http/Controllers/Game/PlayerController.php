<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Application\Player\ItemDetails;
use App\Application\Player\PlayerRules;
use App\Application\Player\PlayerService;
use App\Application\Support\GameAction;
use App\Application\World\WorldService;
use App\Domain\Combat\SnapshotFactory;
use App\Domain\Content\ContentCatalog;
use App\Http\View\Images;
use App\Http\View\ItemLines;
use App\Http\View\UiColors;
use App\Http\View\UnitCards;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class PlayerController
{
    public function __construct(private ContentCatalog $catalog, private ItemDetails $details, private PlayerService $players) {}

    public function roster(Request $request): View
    {
        $characters = $request->user()->characters()->orderBy('id')->get();
        $units = $characters->map(fn ($character) => UnitCards::character($character->toArray(), $this->catalog->get('jobs', $character->job_id)))->all();
        $recruits = [];
        foreach (PlayerRules::RECRUIT_PRICES as $type => $price) {
            $base = $this->catalog->get('base_characters', $type);
            $job = $this->catalog->get('jobs', $base['job']);
            $male = UnitCards::job($job, $base['job']);
            $female = UnitCards::job($job, $base['job'], 1);
            $recruits[] = ['id' => $type, 'price' => $price,
                'male' => $male + ['size' => Images::size($male['img'])],
                'female' => $female + ['size' => Images::size($female['img'])]];
        }

        return view('game.player', ['units' => $units, 'recruits' => $recruits,
            'count' => $characters->count(), 'selected' => $request->user()->preferences['party'] ?? []]);
    }

    public function character(Request $request, int $id): View
    {
        $model = $request->user()->characters()->with('equipment')->findOrFail($id);
        $job = $this->catalog->get('jobs', $model->job_id);
        $this->players->refreshVitals($model);
        $character = $model->toArray();
        $unit = UnitCards::character($character, $job);
        unset($unit['vitals'], $unit['href']);
        $resolvedEquipment = [];
        $equipment = [];
        $weight = 0;
        foreach ($model->equipment as $row) {
            $resolved = $this->details->resolve($row);
            $resolvedEquipment[$row->slot] = $resolved;
            $equipment[$row->slot] = ItemLines::fromInventory($row->toArray(), $resolved);
            $weight += (int) ($resolved['handle'] ?? 0);
        }
        $slots = [];
        foreach (['weapon', 'shield', 'armor', 'item'] as $slot) {
            $slots[] = ['id' => $slot, 'label' => __('hof.slots.'.$slot), 'line' => $equipment[$slot] ?? null];
        }
        $inventory = array_values(array_filter($this->inventoryRows($request), fn ($entry) => in_array($entry['data']['type'], $job['equip'] ?? [], true) && PlayerRules::slot($entry['data']['type']) !== null));
        $passives = $skills = $actions = [];
        foreach ($model->skills as $skillId) {
            $skill = $this->catalog->get('skills', $skillId);
            $skills[] = ItemLines::skill($skill, $skillId);
            if ($skill['passive'] ?? false) {
                $passives[] = $skill;
            }
            if (PlayerRules::isTacticAction((int) $skillId)) {
                $actions[] = ['id' => $skillId, 'label' => $skill['name'].(! empty($skill['sp']) ? ' · SP:'.$skill['sp'] : '')];
            }
        }
        $effective = SnapshotFactory::character($model->stats, $resolvedEquipment, $passives);
        $statusRows = [['label' => '经验', 'value' => $model->xp.' / '.(PlayerRules::experienceRequired($model->level) ?? 'MAX')]];
        foreach (['maxhp', 'maxsp', ...PlayerRules::STATS] as $stat) {
            $statusRows[] = ['label' => __('hof.stats.'.$stat), 'value' => $model->stats[$stat], 'plus' => $effective[$stat] - $model->stats[$stat]];
        }
        $specials = [];
        if (! empty($effective['SPECIAL']['Summon'])) {
            $specials[] = '召唤力 +'.$effective['SPECIAL']['Summon'].'%';
        }
        foreach ([0 => '物理', 1 => '魔法'] as $index => $label) {
            if (! empty($effective['SPECIAL']['Pierce'][$index])) {
                $specials[] = '无视'.$label.'防御伤害 +'.$effective['SPECIAL']['Pierce'][$index];
            }
        }
        if (! empty($effective['SPECIAL']['PoisonResist'])) {
            $specials[] = '毒抵抗 +'.$effective['SPECIAL']['PoisonResist'].'%';
        }
        $allocations = [];
        foreach (PlayerRules::STATS as $stat) {
            $allocations[] = ['id' => $stat, 'label' => __('hof.stats.'.$stat), 'value' => $model->stats[$stat], 'max' => min($model->stat_points, 255 - $model->stats[$stat])];
        }
        $combatRows = [
            ['label' => '物理攻击', 'value' => $effective['atk'][0], 'tone' => 'dmg'],
            ['label' => '魔法攻击', 'value' => $effective['atk'][1], 'tone' => 'spdmg'],
            ['label' => '物理防御', 'value' => $effective['def'][0].' + '.$effective['def'][1], 'tone' => 'recover'],
            ['label' => '魔法防御', 'value' => $effective['def'][2].' + '.$effective['def'][3], 'tone' => 'support'],
        ];
        $availableSkills = [];
        foreach ($this->catalog->availableSkills($model->job_id, $model->level, $model->skills) as $skillId) {
            $skill = $this->catalog->get('skills', $skillId);
            $availableSkills[] = ['id' => $skillId, 'line' => ItemLines::skill($skill, $skillId), 'affordable' => (int) ($skill['learn'] ?? 0) <= $model->skill_points];
        }
        $jobs = [];
        foreach ($this->catalog->all('class_changes') as $jobId => $rule) {
            if ($rule['from_job'] === $model->job_id && $model->level >= $rule['minimum_level']) {
                $jobs[] = UnitCards::job($this->catalog->get('jobs', $jobId), $jobId, $model->gender);
            }
        }
        $conditions = [];
        foreach ($this->catalog->all('conditions') as $conditionId => $condition) {
            if (($condition['css'] ?? false) || ($condition['selectable'] ?? true)) {
                $conditions[] = ['id' => $conditionId, 'label' => $condition['exp'], 'heading' => (bool) ($condition['css'] ?? false)];
            }
        }
        $tactics = $model->tactics;
        $maxPatterns = PlayerRules::maxPatterns((int) $model->stats['int'], $model->level);
        while (count($tactics) < $maxPatterns) {
            $tactics[] = PlayerRules::defaultTactic($model);
        }
        $guards = [];
        foreach (PlayerRules::GUARDS as $guard) {
            $guards[] = ['id' => $guard, 'label' => __('hof.guards.'.$guard)];
        }
        $resetItems = [];
        foreach ([7510, 7511, 7512, 7513, 7520] as $itemId) {
            $resetItems[] = ['id' => $itemId, 'name' => $this->catalog->get('items', $itemId)['name']];
        }
        $characterLinks = $request->user()->characters()->orderBy('id')->get(['id', 'name'])->map(fn ($row) => [
            'label' => $row->name, 'href' => route('player.character', $row->id), 'active' => $row->id === $id,
        ])->all();

        return view('game.player-character', compact('character', 'unit', 'statusRows', 'specials', 'allocations', 'combatRows', 'slots', 'inventory', 'skills', 'availableSkills', 'jobs', 'conditions', 'actions', 'tactics', 'maxPatterns', 'guards', 'resetItems', 'characterLinks', 'effective') + [
            'weight' => $weight, 'capacity' => PlayerRules::capacity($model),
        ]);
    }

    public function inventory(Request $request): View
    {
        $categories = config('hof_ui.item_categories');
        $category = $request->validate(['category' => ['nullable', Rule::in(['all', ...array_keys($categories)])]])['category'] ?? 'all';
        $tabs = [['label' => '全部', 'href' => route('player.inventory'), 'active' => $category === 'all']];
        $groups = [];
        foreach ($categories as $key => $definition) {
            $tabs[] = ['label' => $definition['label'], 'href' => route('player.inventory', ['category' => $key]), 'active' => $category === $key];
            if ($category === 'all' || $category === $key) {
                $groups[$key] = ['label' => $definition['label'], 'items' => []];
            }
        }
        foreach ($this->inventoryRows($request) as $entry) {
            $key = 'other';
            foreach ($categories as $candidate => $definition) {
                if (in_array($entry['data']['type'], $definition['types'], true)) {
                    $key = $candidate;
                    break;
                }
            }
            if (isset($groups[$key])) {
                $groups[$key]['items'][] = $entry;
            }
        }

        return view('game.inventory', compact('tabs', 'groups') + ['expanded' => (bool) ($request->user()->preferences['no_js_inventory'] ?? ! ($request->user()->preferences['inventory_javascript'] ?? true))]);
    }

    public function shop(Request $request): View
    {
        $mode = match ($request->route()->getName()) {
            'player.shop.sell' => 'sell', 'player.shop.work' => 'work', default => 'buy',
        };
        $tabs = [];
        foreach (['buy' => ['player.shop', '买'], 'sell' => ['player.shop.sell', '卖'], 'work' => ['player.shop.work', '打工']] as $key => [$route, $label]) {
            $tabs[] = ['label' => $label, 'href' => route($route), 'active' => $mode === $key];
        }
        $items = [];
        if ($mode === 'buy') {
            foreach ($this->catalog->get('economy_rules', 'shop')['values'] as $id) {
                $resolved = $this->details->resolve(['item_id' => (string) $id]);
                $items[] = ['id' => $id, 'line' => ItemLines::fromCatalog($resolved), 'price' => (int) $resolved['buy'], 'quantity' => 999];
            }
        } elseif ($mode === 'sell') {
            foreach ($this->inventoryRows($request) as $entry) {
                $items[] = ['id' => $entry['id'], 'line' => $entry['line'], 'price' => $entry['data']['sell_price'], 'quantity' => $entry['quantity']];
            }
        }

        return view('game.shop', compact('mode', 'tabs', 'items') + [
            'npc' => ['img' => 'image/char/ori_002.gif', 'alt' => '店员', 'text' => '欢迎光临ー'],
            'stamina' => intdiv(GameAction::availableStamina($request->user(), CarbonImmutable::now()), GameAction::STAMINA_UNIT),
        ]);
    }

    public function crafting(Request $request): View
    {
        $mode = $request->routeIs('player.smithy.create') ? 'create' : 'refine';
        $items = $this->inventoryRows($request);
        $materials = $owned = $refinable = [];
        foreach ($items as $entry) {
            if ($entry['refinement'] === 0 && $entry['enchantments'] === []) {
                $owned[$entry['item_id']] = ($owned[$entry['item_id']] ?? 0) + $entry['quantity'];
                if ((int) $entry['item_id'] >= 7000 && (int) $entry['item_id'] < 7200 && ! empty($entry['data']['Add'])) {
                    $materials[$entry['item_id']] = ['id' => $entry['item_id'], 'name' => $entry['line']['name'], 'quantity' => $owned[$entry['item_id']]];
                }
            }
            if (in_array($entry['data']['type'], PlayerRules::REFINABLE, true) && $entry['refinement'] < 10) {
                $refinable[] = $entry + ['cost' => (int) round($entry['data']['buy'] / 2)];
            }
        }
        $recipes = [];
        foreach ($this->catalog->all('recipes') as $id => $recipe) {
            $ingredients = [];
            foreach ($recipe['ingredients'] as $material => $quantity) {
                $ingredients[] = ['line' => ItemLines::fromCatalog($this->details->resolve(['item_id' => (string) $material])), 'need' => (int) $quantity, 'owned' => $owned[$material] ?? 0];
            }
            $recipes[] = ['id' => $id, 'line' => ItemLines::fromCatalog($this->details->resolve(['item_id' => (string) $id])), 'ingredients' => $ingredients];
        }
        $tabs = [
            ['label' => '精炼工房', 'href' => route('player.smithy.refine'), 'active' => $mode === 'refine'],
            ['label' => '制作工房', 'href' => route('player.smithy.create'), 'active' => $mode === 'create'],
        ];
        $npc = $mode === 'refine'
            ? ['img' => 'image/char/mon_053r.gif', 'alt' => '精炼工匠', 'text' => '在这里可以进行物品的精炼！选择需要精炼的物品以及次数。不过加工坏了我们不负责。弟弟在管理的制作工房在这边。']
            : ['img' => 'image/char/mon_053rz.gif', 'alt' => '制作工匠', 'text' => '在这里可以进行物品的制作！只要你有素材就可以制作装备。加入特殊素材的话可以制作特殊的武器。哥哥在管理的精炼工房在这边。'];

        return view('game.crafting', compact('mode', 'tabs', 'npc', 'items', 'materials', 'refinable', 'recipes'));
    }

    public function preferences(Request $request): View
    {
        $preferences = $request->user()->preferences ?? [];
        $preferences['color'] = UiColors::valid($preferences['color'] ?? null) ? strtolower($preferences['color'] ?? '') : '';
        $preferences['no_js_inventory'] = (bool) ($preferences['no_js_inventory'] ?? ! ($preferences['inventory_javascript'] ?? true));

        return view('account.settings', ['preferences' => $preferences, 'colors' => UiColors::all(), 'teamName' => $request->user()->name]);
    }

    private function inventoryRows(Request $request): array
    {
        return $request->user()->inventory()->where('location', 'backpack')->orderBy('item_id')->orderBy('id')->get()->map(function ($row) {
            $resolved = $this->details->resolve($row);

            return ['id' => $row->id, 'item_id' => $row->item_id, 'quantity' => $row->quantity,
                'refinement' => $row->refinement, 'enchantments' => $row->enchantments,
                'data' => $resolved, 'line' => ItemLines::fromInventory($row->toArray(), $resolved)];
        })->all();
    }

    public function command(Request $request, string $command, WorldService $world): RedirectResponse
    {
        $key = $request->validate(['operation_id' => ['required', 'uuid']])['operation_id'];
        $input = $request->except(['_token', 'operation_id']);
        if (in_array($command, ['buy', 'sell'], true) && is_array($input['items'] ?? null)) {
            $checkedForm = isset($input['selection_mode']) || collect($input['items'])->contains(fn ($row) => is_array($row) && array_key_exists('on', $row));
            if ($checkedForm) {
                // Validate every rendered row; the service limits the selected rows after unchecked rows are removed.
                // Each row includes an unchecked value, so removing the form marker cannot select every row.
                $request->validate(['selection_mode' => ['sometimes', Rule::in(['checked'])],
                    'items' => ['required', 'array', 'min:1'], 'items.*.on' => ['required', 'boolean'],
                    'items.*.id' => ['required', 'integer', 'min:1'], 'items.*.quantity' => ['required', 'integer', 'between:1,999']]);
                $input['items'] = array_values(array_map(fn ($row) => ['id' => $row['id'], 'quantity' => $row['quantity']],
                    array_filter($input['items'], fn ($row) => (bool) $row['on'])));
            } else {
                // Direct command clients can submit explicit quantities; malformed or negative quantities still fail validation.
                $input['items'] = array_values(array_filter($input['items'], fn ($row) => ! is_array($row) || ! isset($row['quantity']) || (string) $row['quantity'] !== '0'));
            }
        }
        if ($command === 'tactics' && $request->input('submit') === 'test') {
            $result = DB::transaction(function () use ($request, $command, $key, $input, $world) {
                $saved = $this->players->execute($request->user()->id, $command, $key, $input);

                return $world->simulate($request->user()->id, $key, [$saved['character_id']], false, 10);
            });

            return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', '行动模式已保存，镜像测试完成。');
        }
        $result = $this->players->execute($request->user()->id, $command, $key, $input);
        $result['message'] = match ($result['message']) {
            'Item crafted.' => '道具制作完成。',
            'Not enough money; item unchanged.' => '资金不足，道具未发生变化。',
            'Refinement failed; item destroyed.' => '精炼失败，道具已损坏。',
            'Refinement stopped: not enough money.' => '资金不足，精炼已停止；已完成的精炼得到保留。',
            'Refinement succeeded.' => '精炼成功。',
            default => $result['message'],
        };
        $redirect = match ($command) {
            'recruit', 'dismiss', 'party' => route('player.roster'),
            'buy' => route('player.shop'), 'sell' => route('player.shop.sell'), 'work' => route('player.shop.work'),
            'craft' => route('player.smithy.create'), 'refine' => route('player.smithy.refine'),
            'preferences', 'team-name' => route('account'),
            default => route('player.character', ['id' => $result['character_id']]),
        };

        return redirect($redirect)->with('status', $result['message'])->with('player_result', $result);
    }
}
