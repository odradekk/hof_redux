@extends('layouts.app')
@section('title', '狩猎')
@section('content')
<x-sec title="狩猎" as="h1" />
<x-sec title="普通怪物" />
<p class="hint">每次狩猎消耗 1 体力，选择 1–5 名角色。</p>
<ul class="inline-list">
    @foreach($areas as $area)
        <li><a href="{{ $area['href'] }}">{{ $area['name'] }}</a>@if($area['proper']) <span class="meta">{{ $area['proper'] }}</span>@endif @if($area['window'])<span class="meta">开放时间：{{ $area['window'] }}</span>@endif</li>
    @endforeach
</ul>
<x-sec title="BOSS"><x-slot:aside>每次挑战消耗 10 体力 · 间隔 20 分钟</x-slot:aside></x-sec>
@if($bosses)
    <div class="carpets carpets-5">
        @foreach($bosses as $boss)
            <x-carpet :unit="$boss['unit']" :href="$boss['href']">
                <x-slot:footer>
                    @if($boss['alive'])<span class="support">可挑战</span>@elseif($boss['respawns_at'])<span class="meta">复活还需 <x-time :at="$boss['respawns_at']" mode="relative" /></span>@else<span class="meta">已被击败</span>@endif
                </x-slot:footer>
            </x-carpet>
        @endforeach
    </div>
@else
    <p class="empty">暂时没有可挑战的 BOSS。</p>
@endif
<x-sec title="BOSS战记录"><x-slot:aside><a href="{{ route('reports.index', ['type' => 'boss']) }}">全表示</a></x-slot:aside></x-sec>
@if($records)<ol class="feed">@foreach($records as $record)<x-battle.record :record="$record" />@endforeach</ol>@else<p class="empty">暂无战斗记录。</p>@endif
@endsection
