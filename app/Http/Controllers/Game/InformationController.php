<?php

namespace App\Http\Controllers\Game;

use App\Application\World\GameData;
use App\Application\World\GameRules;
use App\Models\Announcement;
use Illuminate\Http\Request;

final class InformationController
{
    public const MANUAL = ['basic' => '规则和手册', 'advanced' => '高级指南', 'tutorial' => '教学'];

    public function manual(GameData $data, GameRules $rules, string $section = 'basic')
    {
        abort_unless(isset(self::MANUAL[$section]), 404);

        return view('game.information.manual.'.$section, ['section' => $section, 'data' => $data, 'rules' => $rules, 'constants' => $rules->constants()]);
    }

    public function updates()
    {
        return view('game.information.updates', ['announcements' => Announcement::where('published', true)->latest()->paginate(20)]);
    }

    public function catalog(Request $request, GameData $data, GameRules $rules, ?string $kind = null)
    {
        $input = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        if ($kind === null) {
            $query = trim($input['q'] ?? '');

            return view('game.information.catalog.index', ['kind' => null, 'data' => $data, 'counts' => $data->counts(), 'query' => $query, 'results' => $query === '' ? [] : $data->search($query)]);
        }
        abort_unless(isset(GameData::KINDS[$kind]), 404);
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

        return view('game.information.catalog.'.$kind, $view + ['kind' => $kind, 'data' => $data]);
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

        return view('game.information.catalog.'.rtrim($kind, 's'), $view + ['kind' => $kind, 'data' => $data, 'rules' => $rules]);
    }
}
