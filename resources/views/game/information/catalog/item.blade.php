@extends('layouts.app')
@section('title', '道具 · '.$item['name'])
@section('content')
@include('game.information.catalog.head')
<div class="doc">
@php($money = fn ($amount) => \App\Application\World\GameText::money($amount))
<h2 class="sec">{{ $item['name'] }} <span class="sec-aside">{{ $item['type'] }} · No.{{ $item['id'] }}</span></h2>
<p class="indent"><x-item :line="$item" /></p>
<dl class="kv indent">
<dt>种类</dt><dd>{{ $item['type'] }}@if($item['slot'])（装备位置：{{ $item['slot'] }}）@endif</dd>
@if($item['jobs'])<dt>可装备职业</dt><dd>@foreach($item['jobs'] as $job)<a href="{{ $job['href'] }}">{{ $job['name'] }}</a>{{ $loop->last ? '' : '、' }}@endforeach</dd>@endif
<dt>买价</dt><dd>{{ $money($item['buy']) }}@if($item['shop']) <span class="badge">商店出售</span>@endif</dd>
<dt>卖价</dt><dd>{{ $money($item['sell']) }}</dd>
<dt>精炼</dt><dd>{{ $item['refinable'] ? '可以（最高 +10，每次 '.$money(round($item['buy'] / 2)).'）' : '不可' }}</dd>
<dt>拍卖</dt><dd>{{ $item['auction'] ? '可以出品' : '不可出品' }}</dd>
@if($item['option'] !== '')<dt>原版说明</dt><dd>{{ $item['option'] }}</dd>@endif
</dl>
@php($resets = collect($rules->resetItems())->keyBy('id'))
@if(isset($resets[$item['id']]) || in_array($item['id'], ['7500', '9000'], true) || $item['unlocks'] || $item['special_material'])
<h2 class="sec">用途</h2>
<div class="indent">
@if(isset($resets[$item['id']]))<p>在角色页使用：把{{ $resets[$item['id']]['effect'] }}重置，退回对应的{{ $item['id'] === '7520' ? '技能点，并恢复初始行动模式' : '属性点；使用时会卸下该角色的全部装备' }}。</p>@endif
@if($item['id'] === '7500')<p>在角色页为角色改名时消耗 1 个。</p>@endif
@if($item['id'] === '9000')<p>拍卖会员卡。持有后才能出品和出价，在拍卖会场以 {{ $money(\App\Application\Multiplayer\AuctionService::MEMBERSHIP_PRICE) }} 购买。</p>@endif
@if($item['special_material'])<p>在锻冶屋制作装备时作为特殊材料额外放入 1 个，成品固定获得：{{ $item['special_material']['effect'] }}。</p>@endif
@foreach($item['unlocks'] as $dungeon)<p>仓库中持有时可以进入地下城 <a href="{{ route('dungeons') }}">{{ $dungeon['name'] }}</a>（不会被消耗）。</p>@endforeach
</div>
@endif
@if($item['recipe'])
<h2 class="sec">制作配方 <span class="sec-aside">锻冶屋 · 制作费 {{ $money($item['recipe']['fee']) }}</span></h2>
<ul class="item-list indent">@foreach($item['recipe']['materials'] as $material)<li><x-item :line="array_replace($material['item'], ['stats' => []])" :qty="$material['quantity']" /></li>@endforeach</ul>
<p class="meta indent">制作出的装备会随机获得附加能力，见下方“附魔候选”和 <a href="{{ route('catalog', 'enchants') }}">附魔</a>。</p>
@endif
@if($item['used_in'])
<h2 class="sec">可用于制作 <span class="sec-aside">{{ count($item['used_in']) }} 种</span></h2>
<ul class="item-list indent">@foreach($item['used_in'] as $use)<li><x-item :line="array_replace($use['item'], ['stats' => []])" /> <span class="meta">需要 {{ $use['quantity'] }} 个</span></li>@endforeach</ul>
@endif
@if($item['drops'])
<h2 class="sec">掉落来源 <span class="sec-aside">击倒该怪物时的掉落率</span></h2>
<table class="tbl tbl-stack">
<thead><tr><th>怪物</th><th>等级</th><th>掉落率</th></tr></thead>
<tbody>@foreach($item['drops'] as $drop)<tr><td class="primary">@if($drop['monster']['href'])<a href="{{ $drop['monster']['href'] }}">{{ $drop['monster']['name'] }}</a>@else{{ $drop['monster']['name'] }}@endif @if($drop['monster']['boss'])<span class="badge">共享首领</span>@endif</td><td class="num" data-label="等级">{{ $drop['monster']['level'] }}</td><td class="num" data-label="掉落率">{{ $drop['rate'] }}</td></tr>@endforeach</tbody>
</table>
@endif
@if($item['pool'])
<h2 class="sec">附魔候选 <span class="sec-aside">制作「{{ $item['type'] }}」时</span></h2>
<p class="meta indent">每次制作掷 1–9：1–3 只得低级附魔，4–6 只得高级附魔，7–9 两者都得；候选中等概率抽取。</p>
<dl class="kv indent">
<dt>低级（{{ count($item['pool']['low']) }}）</dt><dd>@foreach($item['pool']['low'] as $enchant)<span class="badge" title="{{ $enchant['effect'] }}">{{ $enchant['effect'] }}</span>@endforeach</dd>
<dt>高级（{{ count($item['pool']['high']) }}）</dt><dd>@foreach($item['pool']['high'] as $enchant)<span class="badge" title="{{ $enchant['effect'] }}">{{ $enchant['effect'] }}</span>@endforeach</dd>
</dl>
@endif
</div>
@endsection
