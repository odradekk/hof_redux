<?php

namespace App\Http\Controllers\Game;

use App\Application\World\WorldService;
use App\Domain\Content\ContentCatalog;
use App\Http\View\UnitCards;
use Illuminate\Http\Request;

final class WorldController
{
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
