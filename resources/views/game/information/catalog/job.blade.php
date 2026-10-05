@extends('game.information.layout')
@section('title', '职业 · '.$job['name'])
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">{{ $job['name'] }}@if($job['name_female'] !== $job['name']) / {{ $job['name_female'] }}@endif <span class="sec-aside">{{ $job['family'] }} · {{ $job['from'] ? '高级职业' : '基本职业' }} · No.{{ $job['id'] }}</span></h2>
<div class="entry-head">
<ul class="units">@foreach($job['images'] as $i => $image)@include('game.information.unit', ['unit' => ['name' => $i ? '女性' : '男性', 'img' => $image['img']]])@endforeach</ul>
<div>
<p>{{ $job['note'] }}</p>
<dl class="kv">
<dt>可装备</dt><dd>@foreach($job['equip'] as $type)<span class="badge">{{ $type }}</span>@endforeach</dd>
<dt>生命系数</dt><dd>× {{ $job['coe'][0] }}（Lv.1 力量 10：{{ \App\Application\World\GameRules::vital((float) $job['coe'][0], 1, 10) }}，Lv.50 力量 100：{{ \App\Application\World\GameRules::vital((float) $job['coe'][0], 50, 100) }}）</dd>
<dt>魔力系数</dt><dd>× {{ $job['coe'][1] }}（Lv.1 智慧 10：{{ \App\Application\World\GameRules::vital((float) $job['coe'][1], 1, 10) }}，Lv.50 智慧 100：{{ \App\Application\World\GameRules::vital((float) $job['coe'][1], 50, 100) }}）</dd>
@if($job['from'])<dt>转职条件</dt><dd><a href="{{ $job['from']['job']['href'] }}">{{ $job['from']['job']['name'] }}</a> 等级 {{ $job['from']['level'] }} 以上</dd>@endif
@if($job['to'])<dt>可转职为</dt><dd>@foreach($job['to'] as $to)<a href="{{ $to['job']['href'] }}">{{ $to['job']['name'] }}</a>（Lv.{{ $to['level'] }}）{{ $loop->last ? '' : '、' }}@endforeach</dd>@endif
</dl>
<p class="meta">转职时会卸下全部装备；已学会的技能保留，之后按新职业的技能树继续学习。</p>
</div>
</div>
@if($job['starter'])
@php($starter = $job['starter'])
<h2 class="sec">雇佣时的初始状态</h2>
<dl class="kv indent">
<dt>雇佣费用</dt><dd>{{ \App\Application\World\GameText::money($starter['price']) }}</dd>
<dt>能力值</dt><dd><ul class="stat-row">@foreach(\App\Application\World\GameText::STATS as $key => $label)<li><b>{{ $label }}</b>{{ $starter['stats'][$key] }}</li>@endforeach</ul></dd>
<dt>配置</dt><dd>{{ $starter['position'] }} · {{ $starter['guard'] }}</dd>
<dt>装备</dt><dd><ul class="item-list">@foreach($starter['equipment'] as $equipment)<li><span class="meta">{{ $equipment['slot'] }}</span> @include('game.information.item-line', ['line' => $equipment['item']])</li>@endforeach</ul></dd>
<dt>技能</dt><dd><ul class="item-list">@foreach($starter['skills'] as $line)<li>@include('game.information.skill-line')</li>@endforeach</ul></dd>
</dl>
<h3 class="sub indent">初始行动模式</h3>
<table class="tbl sample indent"><thead><tr><th>No</th><th>判定</th><th>使用技能</th></tr></thead><tbody>
@foreach($starter['tactics'] as $row)<tr><td>{{ $row['no'] }}</td><td>{{ $row['condition'] }}</td><td>@include('game.information.skill-line', ['line' => $row['skill'], 'parts' => false, 'exp' => false])</td></tr>@endforeach
</tbody></table>
@endif
<h2 class="sec" id="tree">技能树 <span class="sec-aside">{{ count($job['tree']) }} 个技能</span></h2>
<p class="meta indent">满足条件后在角色页“技能”栏用技能点学习。条件中的技能也可以是转职前学会的。</p>
<table class="tbl tbl-stack">
<thead><tr><th>技能</th><th>技能点</th><th>学习条件</th></tr></thead>
<tbody>
@foreach($job['tree'] as $entry)
<tr><td class="primary">@include('game.information.skill-line', ['line' => $entry['skill']])</td><td class="num" data-label="技能点">{{ $entry['skill']['learn'] }}</td><td data-label="条件">{{ $entry['requires'] !== '' ? $entry['requires'] : '无' }}</td></tr>
@endforeach
</tbody></table>
@endsection
