<?php

namespace App\Http\Controllers\Game;

use App\Application\Battle\BattleService;
use App\Models\BattleReport;
use App\Models\BossChallenge;
use App\Models\RankingChallenge;
use Illuminate\Http\Request;

final class ReportController
{
    public function index(Request $request)
    {
        $type = $request->query('type', 'pve');
        abort_unless(in_array($type, ['pve', 'boss', 'pvp'], true), 404);
        $records = match ($type) {
            'boss' => BossChallenge::latest()->paginate(30), 'pvp' => RankingChallenge::whereNotNull('report')->latest()->paginate(30), default => BattleReport::where('mode', 'pve')->where('public', true)->latest()->paginate(30)
        };

        return view('game.reports.index', compact('type', 'records'));
    }

    public function show(Request $request, BattleReport $report, BattleService $battles)
    {
        abort_unless($report->public || $request->user()?->id === $report->user_id || $request->user()?->is_admin, 404);

        return view('game.battle.show', ['report' => $battles->publicReport($report->report)]);
    }

    public function boss(BossChallenge $challenge, BattleService $battles)
    {
        return view('game.battle.show', ['report' => $battles->publicReport($challenge->report)]);
    }

    public function ranking(RankingChallenge $challenge, BattleService $battles)
    {
        abort_unless($challenge->report, 404);

        return view('game.battle.show', ['report' => $battles->publicReport($challenge->report)]);
    }
}
