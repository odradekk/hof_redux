@extends('game.information.layout')
@section('title', '游戏资料')
@section('info')
@include('game.information.catalog.nav')
<p class="prose">这里公开当前版本游戏使用的全部定义数据：职业、道具、技能、怪物、地图、行动条件和附魔，以及成长、战斗、经济和多人玩法的数值规则。页面与游戏读取同一份内容包和同一组规则常量，内容更新后会自动同步。</p>
<form class="search" method="get" action="{{ route('catalog') }}" role="search">
<label for="catalog-q">搜索名称或编号</label> <input id="catalog-q" class="text" name="q" value="{{ $query }}" maxlength="100" placeholder="如：短剑、治疗、1000"> <button class="btn" type="submit">搜索</button>
</form>
@if($query !== '')
<h2 class="sec">搜索结果 <span class="sec-aside">“{{ $query }}”</span></h2>
@forelse($results as $resultKind => $lines)
<h3 class="sub">{{ \App\Application\World\GameData::KINDS[$resultKind] }}（{{ count($lines) }}）</h3>
<ul class="item-list">
@foreach($lines as $line)
<li>@if($resultKind === 'items')@include('game.information.item-line')@elseif($resultKind === 'skills')@include('game.information.skill-line', ['exp' => false])@else<a class="item-name" href="{{ $line['href'] }}">{{ $line['name'] }}</a>@isset($line['level']) <span class="meta">Lv.{{ $line['level'] }}</span>@endisset @endif</li>
@endforeach
</ul>
@empty
<p class="meta">没有找到匹配的资料。</p>
@endforelse
@endif
<h2 class="sec">分类</h2>
<dl class="kv">
<dt><a href="{{ route('catalog', 'jobs') }}">职业</a></dt><dd>{{ $counts['jobs'] }} 种。外观、可装备类型、生命 / 魔力成长系数、转职条件、完整技能树。</dd>
<dt><a href="{{ route('catalog', 'items') }}">道具</a></dt><dd>{{ $counts['items'] }} 种。性能、重量、价格、制作配方、掉落来源、可附魔候选。</dd>
<dt><a href="{{ route('catalog', 'skills') }}">技能</a></dt><dd>{{ $counts['skills'] }} 个。对象、消耗、威力、准备与僵直、特殊效果、学习条件。</dd>
<dt><a href="{{ route('catalog', 'monsters') }}">怪物</a></dt><dd>{{ $counts['monsters'] }} 种。能力值、行动模式、掉落率、出现地图；共享首领的生命与魔力不公开。</dd>
<dt><a href="{{ route('catalog', 'areas') }}">地图</a></dt><dd>{{ $counts['areas'] }} 张可进入的地图。出现条件与每种怪物的出现率。</dd>
<dt><a href="{{ route('catalog', 'conditions') }}">行动条件</a></dt><dd>{{ $counts['conditions'] }} 种可选判定（原版“判定(judge)”）。</dd>
<dt><a href="{{ route('catalog', 'enchants') }}">附魔</a></dt><dd>{{ $counts['enchants'] }} 种制作附加能力，以及各类型装备的候选表。</dd>
<dt><a href="{{ route('catalog', 'rules') }}">数值规则</a></dt><dd>经验表、行动模式行数、负重、精炼成功率、体力、拍卖、竞技场与共享首领规则。</dd>
</dl>
<p class="meta">规则说明见 <a href="{{ route('manual') }}">手册</a>、<a href="{{ route('manual', 'advanced') }}">高级指南</a> 和 <a href="{{ route('manual', 'tutorial') }}">教学</a>。</p>
@endsection
