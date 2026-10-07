@extends('layouts.app')
@section('title', '冒险')
@section('content')
<x-sec title="地下城" as="h1"><x-slot:aside><a href="{{ route('bosses') }}">共享首领</a> · <a href="{{ route('simulation') }}">模拟战</a></x-slot:aside></x-sec>
@if($active)
    <p class="notice indent">队伍正在地下城中探索。<a href="{{ route('dungeon') }}">继续探索</a></p>
@endif
<p class="hint indent">进入地下城后，只有找到出口或主动撤离才能回到城镇。角色 HP 归零即永久死亡；全灭时背包和战利品全部遗失。</p>
<ul class="dungeon-list">
@foreach($dungeons as $dungeon)
    <li class="panel">
        <h3 class="dungeon-name">@if($dungeon['open'] && ! $active)<a href="{{ $dungeon['href'] }}">{{ $dungeon['name'] }}</a>@else{{ $dungeon['name'] }}@endif <span class="meta">{{ $dungeon['proper'] }} · {{ $dungeon['rooms'] }} 个房间</span></h3>
        <p>{{ $dungeon['summary'] }}</p>
        @unless($dungeon['open'])<p class="meta">需要仓库中持有「{{ $dungeon['requires'] }}」</p>@endunless
    </li>
@endforeach
</ul>
<x-sec title="探索记录" />
@if($runs)
    <ol class="feed">
    @foreach($runs as $run)
        <li><a href="{{ $run['href'] }}">{{ $run['name'] }}</a> <span class="{{ $run['tone'] }}">{{ $run['status'] }}</span>@if($run['money']) <span class="meta">带回 <x-money :amount="$run['money']" /></span>@endif <x-time :at="$run['at']" /></li>
    @endforeach
    </ol>
@else
    <p class="empty">还没有探索记录。</p>
@endif
@endsection
