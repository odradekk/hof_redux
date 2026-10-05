<?php

namespace App\Http\Controllers\Game;

use App\Application\Battle\BattlePresenter;
use App\Application\Multiplayer\BossService;
use App\Application\World\WorldService;
use App\Domain\Content\ContentCatalog;
use App\Http\View\UnitCards;
use App\Models\BossChallenge;
use Illuminate\Http\Request;

final class WorldController
{
    public function index(Request $request, WorldService $world, BossService $bosses, BattlePresenter $presenter)
    {
        $areas = [];
        foreach ($world->areas($request->user()) as $id => $area) {
            $unlock = $area['unlock'] ?? [];
            $areas[] = ['name' => $area['name'], 'href' => route('hunt.area', $id), 'proper' => $area['proper'] ?? '', 'window' => ($unlock['kind'] ?? '') === 'daily_window' ? $unlock['from'].'–'.$unlock['until'].' '.$unlock['timezone'] : null];
        }
        $summaries = array_map(static fn (array $boss): array => $boss + ['unit' => UnitCards::boss($boss), 'href' => route('bosses.show', $boss['id'])], $bosses->summaries());
        $records = BossChallenge::latest('id')->limit(15)->get(['id', 'report', 'created_at'])->map(fn (BossChallenge $record): array => $presenter->summary($record->report) + ['href' => route('reports.boss', $record->id), 'at' => $record->created_at])->all();

        return view('game.world.index', ['areas' => $areas, 'bosses' => $summaries, 'records' => $records]);
    }

    public function area(Request $request, string $area, WorldService $world, ContentCatalog $catalog)
    {
        $areas = $world->areas($request->user());
        abort_unless(isset($areas[$area]), 404);
        $enemies = [];
        foreach ($areas[$area]['encounters'] as $id => $entry) {
            if ($entry[1]) {
                $enemies[] = UnitCards::monster($catalog->get('monsters', $id) + ['land' => $areas[$area]['land']], $id);
            }
        }

        return view('game.world.party', [
            'title' => $areas[$area]['name'], 'action' => route('hunt.area', $area),
            'units' => $this->units($request, $catalog), 'selected' => $request->old('party', $request->user()->preferences['party'] ?? []),
            'enemies' => $enemies, 'simulation' => false,
        ]);
    }

    public function simulation(Request $request, ContentCatalog $catalog)
    {
        return view('game.world.party', [
            'title' => '模拟战', 'action' => route('simulation'), 'units' => $this->units($request, $catalog),
            'selected' => $request->old('party', $request->user()->preferences['party'] ?? []), 'enemies' => [], 'simulation' => true,
        ]);
    }

    private function units(Request $request, ContentCatalog $catalog): array
    {
        return $request->user()->characters()->get()->map(static function ($character) use ($catalog): array {
            $unit = UnitCards::character($character->toArray(), $catalog->get('jobs', $character->job_id));
            $unit['label'] .= ' · '.$unit['position'];
            unset($unit['vitals']);

            return $unit;
        })->all();
    }

    private function input(Request $request): array
    {
        return $request->validate(['operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct|min:1', 'remember' => 'sometimes|boolean']);
    }

    public function hunt(Request $request, string $area, WorldService $world)
    {
        $data = $this->input($request);
        $result = $world->hunt($request->user()->id, $data['operation_id'], $area, $data['party'], $request->boolean('remember'));

        return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', '战斗结束，奖励已结算。');
    }

    public function simulate(Request $request, WorldService $world)
    {
        $data = $this->input($request);
        $result = $world->simulate($request->user()->id, $data['operation_id'], $data['party'], $request->boolean('remember'));

        return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', '模拟战结束，不消耗体力，不保存奖励或伤害。');
    }

    public function characterSimulation(Request $request, int $character, WorldService $world)
    {
        $data = $request->validate(['operation_id' => 'required|uuid']);
        $result = $world->simulate($request->user()->id, $data['operation_id'], [$character], false, 10);

        return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', '模拟战结束，不消耗体力，不保存奖励或伤害。');
    }
}
