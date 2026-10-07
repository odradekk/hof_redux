<?php

namespace App\Http\Controllers\Game;

use App\Application\Battle\BattlePresenter;
use App\Application\Battle\BattleService;
use App\Models\BattleReport;
use App\Models\BossChallenge;
use App\Models\RankingChallenge;
use Illuminate\Http\Request;

final class ReportController
{
    public function index(Request $request, BattlePresenter $presenter)
    {
        $type = $request->query('type', 'pve');
        abort_unless(in_array($type, ['pve', 'boss', 'pvp'], true), 404);
        $records = match ($type) {
            'boss' => BossChallenge::latest()->paginate(30), 'pvp' => RankingChallenge::whereNotNull('report')->latest()->paginate(30), default => BattleReport::where('mode', 'pve')->where('public', true)->latest()->paginate(30),
        };
        $route = match ($type) {
            'boss' => 'reports.boss', 'pvp' => 'reports.ranking', default => 'reports.show'
        };
        $entries = $records->getCollection()->map(fn ($record): array => $presenter->summary($record->report) + ['href' => route($route, $record->id), 'at' => $record->created_at])->all();
        $tabs = [];
        foreach (config('hof_ui.report_tabs', []) as $key => $tab) {
            $tabType = $tab['parameters']['type'] ?? $key;
            $tabs[] = ['label' => $tab['label'], 'href' => route('reports.index', ['type' => $tabType]), 'active' => $type === $tabType];
        }

        return view('game.reports.index', compact('type', 'records', 'entries', 'tabs'));
    }

    public function show(Request $request, BattleReport $report, BattleService $battles)
    {
        abort_unless($report->public || $request->user()?->id === $report->user_id || $request->user()?->is_admin, 404);
        $retry = null;
        if ($request->user() && $request->user()->id === $report->user_id) {
            $party = $this->party($request, $report->report);
            if ($party && ($report->report['mode'] ?? '') === 'simulation') {
                // Older capped reports reveal their bound; an early finish alone does not.
                $limit = $report->report['action_limit'] ?? (($report->report['reason'] ?? '') === 'action_limit' ? ($report->report['actions'] ?? null) : null);
                if ($limit === 10 && count($party) === 1) {
                    $retry = ['action' => route('character.simulation', $party[0]), 'party' => $party];
                } elseif ($limit === BattleService::SIMULATION_ACTION_LIMIT) {
                    $retry = ['action' => route('simulation'), 'party' => $party];
                }
            }
        }

        return view('game.battle.show', ['report' => $battles->publicReport($report->report), 'createdAt' => $report->created_at, 'retry' => $retry]);
    }

    public function boss(Request $request, BossChallenge $challenge, BattleService $battles)
    {
        $party = $request->user() && $request->user()->id === $challenge->user_id ? $this->party($request, $challenge->report) : [];
        $retry = $party ? ['action' => route('bosses.challenge', $challenge->boss_instance_id), 'party' => $party] : null;

        return view('game.battle.show', ['report' => $battles->publicReport($challenge->report), 'createdAt' => $challenge->created_at, 'retry' => $retry]);
    }

    public function ranking(RankingChallenge $challenge, BattleService $battles)
    {
        abort_unless($challenge->report, 404);

        return view('game.battle.show', ['report' => $battles->publicReport($challenge->report), 'createdAt' => $challenge->created_at]);
    }

    private function party(Request $request, array $report): array
    {
        $party = [];
        foreach ($report['initial_teams'][0] ?? [] as $unit) {
            if (! ($unit['summon'] ?? false) && preg_match('/^player:([1-9][0-9]*)$/', (string) ($unit['id'] ?? ''), $match)) {
                $party[] = (int) $match[1];
            }
        }
        if (! $party || count($party) > 5 || $request->user()->characters()->whereIn('id', $party)->count() !== count($party)) {
            return [];
        }

        return $party;
    }
}
