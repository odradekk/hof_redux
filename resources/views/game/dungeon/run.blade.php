@extends('layouts.app')
@section('title', $name)
@section('content')
<h1 class="page-title">{{ $name }}<span class="meta">第 {{ $steps }} 步 · 战利品 <x-money :amount="$money" /></span></h1>

<figure class="dmap-frame">
<svg class="dmap" viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" role="img" aria-label="地下城地图">
    @foreach($map['edges'] as $edge)
        <line @class(['dmap-edge', 'is-known' => $edge['known']]) x1="{{ $edge['x1'] }}" y1="{{ $edge['y1'] }}" x2="{{ $edge['x2'] }}" y2="{{ $edge['y2'] }}" />
    @endforeach
    @foreach($map['rooms'] as $node)
        <g @class(['dmap-room', 'type-'.$node['type'], 'is-current' => $node['current'], 'is-cleared' => $node['cleared'], 'is-unknown' => ! $node['known']])>
            <title>{{ $node['label'] }}（{{ $node['kind'] }}）</title>
            <rect x="{{ $node['x'] - 46 }}" y="{{ $node['y'] - 20 }}" width="92" height="40" rx="4" />
            <text x="{{ $node['x'] }}" y="{{ $node['y'] - 3 }}" text-anchor="middle">{{ mb_strimwidth($node['label'], 0, 12, '…') }}</text>
            <text class="dmap-kind" x="{{ $node['x'] }}" y="{{ $node['y'] + 13 }}" text-anchor="middle">{{ $node['kind'] }}</text>
        </g>
    @endforeach
</svg>
<figcaption class="hint">只显示到过的房间和相邻的房间；未探索的房间不显示内容。</figcaption>
</figure>

<div class="dungeon-grid">
<section>
    <x-sec :title="'当前位置：'.$room['name']"><x-slot:aside>{{ $room['kind'] }}</x-slot:aside></x-sec>
    <div class="indent">
    @switch($room['type'])
        @case('chest')
            @if($room['cleared'])<p class="meta">宝箱已经打开过了。</p>
            @else
                <p>房间里放着一个宝箱。</p>
                <form method="post" action="{{ route('dungeon.act', 'open') }}"><x-op /><div class="actions"><button class="btn" type="submit">打开宝箱</button><span class="hint">每名成员消耗 {{ $room['open_stamina'] }} 体力</span></div></form>
            @endif
            @break
        @case('event')
            <p>{{ $room['text'] }}</p>
            @if($room['cleared'])<p class="meta">这里已经没有什么了。</p>
            @else
                <div class="actions">@foreach($room['choices'] as $i => $label)<form method="post" action="{{ route('dungeon.act', 'choose') }}"><x-op /><input type="hidden" name="choice" value="{{ $i }}"><button class="btn" type="submit">{{ $label }}</button></form>@endforeach</div>
            @endif
            @break
        @case('rest')
            @if($room['uses'] > 0)
                <p>可以在这里休息：HP 和 SP 各恢复上限的 {{ $room['heal'] }}%，体力 +{{ $room['stamina'] }}。</p>
                <form method="post" action="{{ route('dungeon.act', 'rest') }}"><x-op /><div class="actions"><button class="btn" type="submit">休息</button><span class="hint">剩余 {{ $room['uses'] }} 次</span></div></form>
            @else<p class="meta">这里已经不能再休息了。</p>@endif
            @break
        @case('exit')
            <p>找到了出口。离开后战利品和背包会存入仓库，并获得通关奖励。</p>
            <form method="post" action="{{ route('dungeon.act', 'leave') }}"><x-op /><div class="actions"><button class="btn btn-lg" type="submit">离开地下城</button></div></form>
            @break
        @case('battle')
            <p class="meta">{{ $room['cleared'] ? '这里的敌人已被击败。' : '敌人还在这里。' }}</p>
            @break
        @case('trap')
            <p class="meta">陷阱已经触发过了。</p>
            @break
        @default
            <p class="meta">这里什么也没有。</p>
    @endswitch
    </div>

    <x-sec title="移动"><x-slot:aside>每名成员 -{{ $rules['move'] }} 体力</x-slot:aside></x-sec>
    <ul class="item-list indent">
    @foreach($exits as $exit)
        <li><form method="post" action="{{ route('dungeon.move') }}" class="inline-form"><x-op /><input type="hidden" name="room" value="{{ $exit['id'] }}"><button class="btn" type="submit">前往</button> {{ $exit['label'] }} <span class="meta">{{ $exit['kind'] }}</span></form></li>
    @endforeach
    </ul>
</section>

<section>
    <x-sec title="背包"><x-slot:aside>战斗中不能使用道具</x-slot:aside></x-sec>
    @if($pack)
        <ul class="item-list indent">
        @foreach($pack as $entry)
            <li><form method="post" action="{{ route('dungeon.act', 'use') }}" class="inline-form"><x-op /><input type="hidden" name="item" value="{{ $entry['id'] }}">
                <x-item :line="$entry['line']" />
                <select class="input input-md" name="character" aria-label="对谁使用{{ $entry['line']['name'] }}">@foreach($living as $member)<option value="{{ $member['id'] }}">{{ $member['name'] }}</option>@endforeach</select>
                <button class="btn" type="submit">使用</button></form></li>
        @endforeach
        </ul>
    @else
        <p class="empty indent">背包是空的。</p>
    @endif
    <x-sec title="战利品"><x-slot:aside>离开或撤离后存入仓库</x-slot:aside></x-sec>
    <p class="indent">资金 <x-money :amount="$money" /></p>
    @if($loot)<ul class="item-list indent">@foreach($loot as $line)<li><x-item :line="$line" /></li>@endforeach</ul>@endif
</section>
</div>

<x-sec title="队伍"><x-slot:aside>体力越低属性越低</x-slot:aside></x-sec>
<div class="carpets carpets-5">
@foreach($party as $member)
    <x-carpet :unit="$member['unit']" :class="$member['fallen'] ? 'is-fallen' : ''">
        <x-slot:footer>@if($member['fallen'])<span class="dmg">已阵亡</span>@elseif($member['fatigue'])<span class="charge">疲劳 -{{ $member['fatigue'] }}%</span>@endif</x-slot:footer>
    </x-carpet>
@endforeach
</div>

<x-sec title="探索日志" />
<ol class="feed">
@foreach($events as $event)
    <li>{{ $event['text'] }}@if($event['report']) <a href="{{ $event['report'] }}">战报</a>@endif <x-time :at="$event['at']" /></li>
@endforeach
</ol>

<section class="danger-zone">
    <x-sec title="撤离" />
    <form method="post" action="{{ route('dungeon.act', 'retreat') }}" class="indent"><x-op />
        <p>放弃剩余的房间，带着背包和目前的战利品回到城镇。不会获得通关奖励。</p>
        <div class="actions"><x-confirm word="撤离" /><button class="btn btn-danger" type="submit">撤离</button></div>
    </form>
</section>
@endsection
