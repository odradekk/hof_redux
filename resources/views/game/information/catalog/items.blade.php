@extends('game.information.layout')
@section('title', '游戏资料 · 道具')
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">道具(Item)</h2>
<ul class="toc">
@foreach($groups as $category => $types)
<li>{{ $category }}：@foreach($types as $type => $rows)<a href="#type-{{ $loop->parent->index }}-{{ $loop->index }}">{{ $type }}</a>{{ $loop->last ? '' : '、' }}@endforeach</li>
@endforeach
</ul>
<p class="meta indent"><span class="dmg">物理攻击</span> / <span class="spdmg">魔法攻击</span> / <span class="recover">物理防御 a+b</span> / <span class="support">魔法防御 c+d</span>：防御的 a 是减伤百分比，b 是之后再直接扣除的数值。<span class="charge">重量</span> 计入角色负重。卖价为买价的 1/5（另有设定的除外）。</p>
@foreach($groups as $category => $types)
@foreach($types as $type => $rows)
<h2 class="sec" id="type-{{ $loop->parent->index }}-{{ $loop->index }}">{{ $type }} <span class="sec-aside">{{ $category }} · {{ count($rows) }} 种</span></h2>
<table class="tbl tbl-stack">
<thead><tr><th>道具</th><th>买价</th><th>卖价</th><th>获得</th></tr></thead>
<tbody>
@foreach($rows as $line)
<tr><td class="primary">@include('game.information.item-line')</td><td class="num" data-label="买价">{{ \App\Application\World\GameText::money($line['buy']) }}</td><td class="num" data-label="卖价">{{ \App\Application\World\GameText::money($line['sell']) }}</td><td class="sources" data-label="获得">@foreach($line['sources'] as $source)<span class="badge">{{ $source }}</span>@endforeach</td></tr>
@endforeach
</tbody></table>
@endforeach
@endforeach
@endsection
