<?php

namespace App\Http\Controllers\Game;

use App\Application\World\GameData;
use App\Application\World\GameRules;
use App\Models\Announcement;
use Illuminate\Http\Request;

final class InformationController
{
    public function manual(GameData $data, GameRules $rules, string $section = 'basic')
    {
        $registry = config('hof_ui.manual_tabs', []);
        abort_unless(isset($registry[$section]), 404);
        $tabs = [...$this->tabs($registry, $section), ['label' => '游戏数据', 'href' => route('catalog'), 'active' => false]];

        return view('game.information.manual.'.$section, [
            'title' => $registry[$section]['label'], 'tabs' => $tabs,
            'data' => $data, 'rules' => $rules, 'constants' => $rules->constants(),
        ]);
    }

    public function updates()
    {
        return view('game.information.updates', ['announcements' => Announcement::where('published', true)->latest()->paginate(20)]);
    }

    public function catalog(Request $request, GameData $data, GameRules $rules, ?string $kind = null)
    {
        $registry = config('hof_ui.catalog_tabs', []);
        abort_unless($kind === null || isset($registry[$kind]), 404);
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $shared = ['kind' => $kind, 'data' => $data, 'tabs' => $this->catalogTabs($kind), 'labels' => array_map(static fn (array $tab): string => $tab['label'], $registry)];
        if ($kind === null) {
            $query = trim($input['q'] ?? '');

            return view('game.information.catalog.index', $shared + ['counts' => $data->counts(), 'query' => $query, 'results' => $query === '' ? [] : $data->search($query)]);
        }
        $view = match ($kind) {
            'jobs' => ['jobs' => $data->jobs()],
            'items' => ['groups' => $data->items()],
            'skills' => ['groups' => $data->skills()],
            'monsters' => ['groups' => $data->monsters()],
            'areas' => ['areas' => $data->areas()],
            'conditions' => ['groups' => $data->conditions()],
            'enchants' => $data->enchants(),
            'rules' => ['rules' => $rules, 'constants' => $rules->constants()],
        };

        return view('game.information.catalog.'.$kind, $view + $shared);
    }

    public function entry(GameData $data, GameRules $rules, string $kind, string $id)
    {
        abort_unless(in_array($kind, GameData::DETAILS, true) && $data->exists($kind, $id), 404);
        $view = match ($kind) {
            'jobs' => ['job' => $data->job($id)],
            'items' => ['item' => $data->item($id)],
            'skills' => ['skill' => $data->skill($id)],
            'monsters' => ['monster' => $data->monster($id)],
        };

        return view('game.information.catalog.'.rtrim($kind, 's'), $view + ['kind' => $kind, 'data' => $data, 'rules' => $rules, 'tabs' => $this->catalogTabs($kind)]);
    }

    /** The legacy "| 职业(Job) | 道具(item) | 判定 |" switcher, led by the overview page. */
    private function catalogTabs(?string $kind): array
    {
        return [['label' => '总览', 'href' => route('catalog'), 'active' => $kind === null], ...$this->tabs(config('hof_ui.catalog_tabs', []), $kind)];
    }

    private function tabs(array $registry, ?string $active): array
    {
        $tabs = [];
        foreach ($registry as $key => $tab) {
            $tabs[] = ['label' => $tab['label'], 'href' => route($tab['route'], $tab['parameters'] ?? []), 'active' => $key === $active];
        }

        return $tabs;
    }
}
