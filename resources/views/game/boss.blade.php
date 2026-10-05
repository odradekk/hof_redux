@extends('layouts.app')
@section('title', '共享首领')
@section('content')
<x-sec as="h1" title="共享首领(BOSS)">
    <x-slot:aside><a href="{{ route('hunt') }}">返回狩猎</a></x-slot:aside>
</x-sec>
<p class="hint">每次消耗10体力，每20分钟可挑战一次。所有玩家共同挑战首领，首领生命与魔力不会公开。</p>
@if(!$ready)<p>下次可挑战：<x-time :at="$readyAt" mode="relative" /></p>@endif
@forelse($bosses as $summary)
    <section>
        <x-sec :title="$summary['name']" as="h2" />
        <div class="split">
            <x-carpet :unit="$summary['unit']" :href="route('bosses.show', $summary['id'])" />
            <div>
                <p>队伍等级上限：<span class="num">{{ $summary['limit'] }}</span></p>
                @if($summary['alive'])<p class="recover">存活</p>
                @else<p>已击败，复活时间：<x-time :at="$summary['respawns_at']" mode="relative" /></p>@endif
            </div>
        </div>
        @if($summary['alive'])
            @if($boss !== null)
                <x-sortie :units="$units" :selected="$selected" :action="route('bosses.challenge', $summary['id'])">
                    <x-slot:actions><button class="btn btn-lg" type="submit" @disabled(!$ready)>战斗!</button><button class="btn" type="reset">重置</button></x-slot:actions>
                </x-sortie>
            @else
                <p class="actions"><a class="btn" href="{{ route('bosses.show', $summary['id']) }}">选择出战队伍</a></p>
            @endif
        @endif
    </section>
@empty
    <p class="empty">暂无共享首领</p>
@endforelse
<x-sec title="我的挑战" as="h2" />
<x-feed :entries="$challenges" />
@endsection
