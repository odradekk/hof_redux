<?php

namespace App\Http\Controllers\Game;

use App\Application\World\WorldService;
use App\Domain\Content\ContentCatalog;
use Illuminate\Http\Request;

final class WorldController
{
    public function index(Request $request, WorldService $world)
    {
        return view('game.world.index', ['areas' => $world->areas($request->user())]);
    }

    public function area(Request $request, string $area, WorldService $world, ContentCatalog $catalog)
    {
        $areas = $world->areas($request->user());
        abort_unless(isset($areas[$area]), 404);
        $enemies = [];
        foreach ($areas[$area]['encounters'] as $id => $entry) {
            if ($entry[1]) {
                $enemies[$id] = $catalog->get('monsters', $id);
            }
        }

        return view('game.world.party', ['area' => $areas[$area], 'areaId' => $area, 'characters' => $request->user()->characters()->get(), 'enemies' => $enemies, 'simulation' => false]);
    }

    public function simulation(Request $request)
    {
        return view('game.world.party', ['characters' => $request->user()->characters()->get(), 'enemies' => [], 'simulation' => true]);
    }

    private function input(Request $request): array
    {
        return $request->validate(['operation_id' => 'required|uuid', 'party' => 'required|array|min:1|max:5', 'party.*' => 'required|integer|distinct|min:1', 'remember' => 'sometimes|boolean']);
    }

    public function hunt(Request $request, string $area, WorldService $world)
    {
        $data = $this->input($request);
        $result = $world->hunt($request->user()->id, $data['operation_id'], $area, $data['party'], $request->boolean('remember'));

        return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', $result['message']);
    }

    public function simulate(Request $request, WorldService $world)
    {
        $data = $this->input($request);
        $result = $world->simulate($request->user()->id, $data['operation_id'], $data['party'], $request->boolean('remember'));

        return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', $result['message']);
    }

    public function characterSimulation(Request $request, int $character, WorldService $world)
    {
        $data = $request->validate(['operation_id' => 'required|uuid']);
        $result = $world->simulate($request->user()->id, $data['operation_id'], [$character], false, 10);

        return redirect()->route('reports.show', ['report' => $result['report_id']])->with('status', $result['message']);
    }
}
