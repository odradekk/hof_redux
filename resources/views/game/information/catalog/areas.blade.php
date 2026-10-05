@extends('game.information.layout')
@section('title', '游戏资料 · 地图')
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">地图(Map)</h2>
<ul class="inline-list indent">@foreach($areas['open'] as $area)<li><a href="#area-{{ $area['id'] }}">{{ $area['name'] }}</a></li>@endforeach</ul>
<p class="meta indent">每次狩猎出现的敌人数与出战人数相同；每个敌人位独立按下表的出现率抽取，同一种怪物可以重复出现。“隐藏”的怪物不会显示在冒险页的“出现敌人”中。</p>
@foreach($areas['open'] as $area)
<h2 class="sec" id="area-{{ $area['id'] }}">{{ $area['name'] }}@if($area['name0'] !== '') <span class="meta">{{ $area['name0'] }}</span>@endif <span class="sec-aside">推荐 {{ $area['proper'] }}</span></h2>
<dl class="kv indent">
<dt>出现条件</dt><dd>{{ $area['unlock'] }}@if($area['unlock_item']) · @include('game.information.item-line', ['line' => $area['unlock_item'], 'stats' => false])@endif</dd>
</dl>
<ul class="units">
@foreach($area['encounters'] as $encounter)
@include('game.information.unit', ['unit' => $encounter['monster'], 'land' => $area['land'], 'caption' => 'Lv.'.$encounter['monster']['level'].' · '.$encounter['rate'].($encounter['shown'] ? '' : ' · 隐藏')])
@endforeach
</ul>
@endforeach
@if($areas['closed'])
<h2 class="sec">未开放地区 <span class="sec-aside">资料保留，当前无法进入</span></h2>
<details class="indent"><summary>展开 {{ count($areas['closed']) }} 个地区</summary>
@foreach($areas['closed'] as $area)
<h3 class="sub">{{ $area['name'] }}@if($area['name0'] !== '') <span class="meta">{{ $area['name0'] }}</span>@endif</h3>
<ul class="item-list">@foreach($area['encounters'] as $encounter)<li>@if($encounter['monster']['href'])<a href="{{ $encounter['monster']['href'] }}">{{ $encounter['monster']['name'] }}</a>@else{{ $encounter['monster']['name'] }}@endif <span class="meta">Lv.{{ $encounter['monster']['level'] }} · {{ $encounter['rate'] }}</span></li>@endforeach</ul>
@endforeach
</details>
@endif
@endsection
