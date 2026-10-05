@extends('layouts.app')
@section('title', '竞技场')
@section('content')
<x-sec as="h1" title="竞技场(Ranking)" />
<p class="hint">登记1–5名角色，每48小时可重设队伍。胜利后等待60秒，失败或平局等待24小时。第一名不可继续挑战。</p>
<div class="split split-stack">
    @foreach($rankings as $ranking)
        <section>
            <x-sec :title="$ranking['label']" as="h2" />
            <table class="tbl">
                <thead><tr><th scope="col">排位</th><th scope="col">队伍</th></tr></thead>
                <tbody>
                    @forelse($ranking['rows'] as $group)
                        <tr>
                            <th scope="row" class="c">@if($group['crown'])<img class="icon" src="{{ asset($group['crown']) }}" alt="{{ $group['label'] }}" width="24" height="24">@else{{ $group['label'] }}@endif</th>
                            <td>@foreach($group['entries'] as $entry)<p><span @class(['bold u' => $entry['own']])>{{ $entry['name'] }}</span><br><span class="meta">{{ $entry['record'] }}</span></p>@endforeach</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="empty">暂无竞技场排名</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endforeach
</div>
@auth
    <x-sec title="登记队伍" as="h2" />
    @if(!$canRegister)<p class="hint">下次可重设队伍：<x-time :at="$teamReadyAt" mode="relative" /></p>@endif
    <x-sortie :units="$units" :selected="$selected" :action="route('ranking.team')">
        <x-slot:actions><button class="btn" type="submit" @disabled(!$canRegister)>保存队伍</button><button class="btn" type="reset">重置</button></x-slot:actions>
    </x-sortie>
    @if($team)
        <x-sec title="挑战" as="h2" />
        @if($ownPlace === 1)<p class="hint">您已位居第一名，等待其他队伍挑战。</p>
        @elseif($challengeAt && !$canChallenge)<p>下次可挑战：<x-time :at="$challengeAt" mode="relative" /></p>
        @else<p>现在可以挑战上一级随机队伍。</p>@endif
        <form method="post" action="{{ route('ranking.challenge') }}" class="actions">
            <x-op /><button class="btn btn-lg" type="submit" @disabled(!$canChallenge)>挑战!</button>
        </form>
    @endif
@endauth
<x-sec title="挑战记录" as="h2" />
<x-feed :entries="$challenges" />
@endsection
