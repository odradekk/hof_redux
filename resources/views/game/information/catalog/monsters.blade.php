@extends('game.information.layout')
@section('title', '游戏资料 · 怪物')
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">怪物(Monster)</h2>
<ul class="inline-list indent">@foreach($groups as $group => $rows)<li><a href="#group-{{ $loop->index }}">{{ $group }}</a> <span class="meta">{{ count($rows) }}</span></li>@endforeach</ul>
<p class="meta indent">按第一次出现的可进入地图分组。经验与金钱是击倒一只时全队获得的总量（经验由存活的角色平分）。共享首领的生命、魔力与奖励按原版规则不公开，显示为“????”。</p>
@foreach($groups as $group => $rows)
<h2 class="sec" id="group-{{ $loop->index }}">{{ $group }} <span class="sec-aside">{{ count($rows) }} 种</span></h2>
<table class="tbl tbl-stack">
<thead><tr><th>怪物</th><th>Lv</th><th>生命</th><th>魔力</th>@foreach(\App\Application\World\GameText::STATS as $label)<th>{{ $label }}</th>@endforeach<th>经验</th><th>金钱</th><th>掉落</th></tr></thead>
<tbody>
@foreach($rows as $row)
<tr>
<td class="primary"><img class="sprite" src="{{ asset($row['img']) }}" alt="" loading="lazy"> <a class="item-name" href="{{ $row['href'] }}">{{ $row['name'] }}</a></td>
<td class="num" data-label="Lv">{{ $row['level'] }}</td>
<td class="num" data-label="生命">{{ $row['hp'] === null ? '????' : number_format($row['hp']) }}</td>
<td class="num" data-label="魔力">{{ $row['sp'] === null ? '????' : number_format($row['sp']) }}</td>
@foreach(\App\Application\World\GameText::STATS as $key => $label)<td class="num" data-label="{{ $label }}">{{ $row['stats'][$key] }}</td>@endforeach
<td class="num" data-label="经验">{{ $row['exp'] === null ? '????' : number_format($row['exp']) }}</td>
<td class="num" data-label="金钱">{{ $row['money'] === null ? '????' : number_format($row['money']) }}</td>
<td class="num" data-label="掉落">{{ $row['drops'] ? $row['drops'].' 种' : '—' }}</td>
</tr>
@endforeach
</tbody></table>
@endforeach
@endsection
