<?php

namespace App\Http\Controllers;

use App\Application\Multiplayer\RankingService;
use App\Domain\Content\ContentCatalog;
use App\Http\View\UnitCards;
use App\Models\RankingEntry;
use App\Models\User;
use Illuminate\Http\Request;

final class HomeController
{
    public function index(Request $request, ContentCatalog $catalog)
    {
        $cards = $request->user()->characters()->orderBy('id')->get()->map(fn ($character) => UnitCards::character($character->toArray(), $catalog->get('jobs', $character->job_id)) + ['href' => route('player.character', $character->id)])->all();

        return view('home', ['cards' => $cards, 'teamName' => $request->user()->name, 'tutorial' => $request->user()->created_at->greaterThan(now()->subHour())]);
    }

    public function landing()
    {
        $names = User::pluck('name', 'id');
        $ranking = RankingEntry::whereNotNull('position')->orderBy('position')->limit(5)->get()->map(function ($entry) use ($names) {
            $total = $entry->wins + $entry->losses + $entry->draws;

            return ['name' => $names[$entry->user_id] ?? '无名队伍', 'position' => RankingService::place($entry->position), 'wins' => $entry->wins, 'losses' => $entry->losses, 'draws' => $entry->draws, 'defenses' => $entry->defenses, 'total' => $total, 'rate' => $total ? round($entry->wins * 100 / $total) : 0];
        })->all();

        return view('auth.login', ['ranking' => $ranking, 'userCount' => User::count()]);
    }
}
