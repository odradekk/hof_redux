@extends('game.information.layout')
@section('title', '游戏资料 · 技能')
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">技能(Skill)</h2>
<ul class="inline-list indent">@foreach($groups as $family => $lines)<li><a href="#family-{{ $loop->index }}">{{ $family }}</a> <span class="meta">{{ count($lines) }}</span></li>@endforeach</ul>
<p class="meta indent">图标 名称 / 对象 - 范围 / 消耗魔力 / 威力%×次数 / 属性 / 附加效果 / (准备 : 僵直) / 说明。<span class="dmg">敌方</span>、<span class="recover">友方</span>、<span class="support">自己</span>、<span class="charge">战场全体</span>；准备越大越晚发动，僵直越大下次行动越晚。按可以学习该技能的职业系分组，各职业的完整技能树见 <a href="{{ route('catalog', 'jobs') }}">职业</a>。</p>
@foreach($groups as $family => $lines)
<h2 class="sec" id="family-{{ $loop->index }}">{{ $family }} <span class="sec-aside">{{ count($lines) }} 个</span></h2>
<table class="tbl tbl-stack">
<thead><tr><th>技能</th><th>技能点</th></tr></thead>
<tbody>
@foreach($lines as $line)
<tr><td class="primary">@include('game.information.skill-line')</td><td class="num" data-label="技能点">{{ $line['learn'] }}</td></tr>
@endforeach
</tbody></table>
@endforeach
@endsection
