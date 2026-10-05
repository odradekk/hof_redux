@extends('layouts.app')
@section('title','竞技场')
@section('content')
<h4>竞技场 · Ranking</h4><p>队伍 1–5 人，每 48 小时可重设 · 胜利后等待 60 秒，失败或平局等待 24 小时 · First place cannot challenge</p>
<table><tr><th>排位</th><th>队伍</th><th>胜 / 败 / 平 / 防守</th></tr>@forelse($entries as $entry)<tr><td>{{ \App\Application\Multiplayer\RankingService::place($entry->position) }}</td><td>{{ $names[$entry->user_id] ?? '—' }}</td><td>{{ $entry->wins }} / {{ $entry->losses }} / {{ $entry->draws }} / {{ $entry->defenses }}</td></tr>@empty<tr><td colspan="3">No ranking teams yet. Register a party and challenge to establish the ladder.</td></tr>@endforelse</table>
@auth<h4>竞技队伍 · Team</h4><form method="post" action="{{ route('ranking.team') }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) Str::uuid() }}">@foreach($characters as $character)<label><input type="checkbox" name="party[]" value="{{ $character->id }}" @checked(in_array($character->id,$team?->party??[]))>{{ $character->name }} Lv.{{ $character->level }}</label>@endforeach<button>保存队伍</button></form>
@if($team)<p>Team changed: {{ $team->party_set_at }} UTC · Next challenge: {{ $team->challenge_at ?? 'Ready' }}</p><form method="post" action="{{ route('ranking.challenge') }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) Str::uuid() }}"><button>挑战上一级随机队伍</button></form>@endif @endauth
<h4>挑战记录 · History</h4><ul>@foreach($challenges as $challenge)<li>{{ $challenge->created_at }} · {{ $names[$challenge->challenger_id]??'—' }} vs {{ $names[$challenge->defender_id]??'—' }} · {{ $challenge->result }} @auth<a href="{{ route('multiplayer.report',['ranking',$challenge->id]) }}">战报</a>@endauth</li>@endforeach</ul>
@endsection
