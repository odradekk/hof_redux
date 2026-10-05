@extends('layouts.app')
@section('title', '技能 · '.$skill['name'])
@section('content')
@include('game.information.catalog.head')
<div class="doc">
<h2 class="sec">{{ $skill['name'] }} <span class="sec-aside">{{ $skill['category'] }} · No.{{ $skill['id'] }}</span></h2>
<p class="indent"><x-skill :line="$skill" /></p>
<dl class="kv indent">
<dt>类别</dt><dd>{{ $skill['category'] }}</dd>
<dt>学习所需</dt><dd>{{ $skill['learn'] }} 技能点</dd>
<dt>行动模式</dt><dd>{{ $skill['action'] ? '可以设为行动' : '被动生效，不能设为行动' }}</dd>
@if($skill['exp'] !== '')<dt>说明</dt><dd>{{ $skill['exp'] }}</dd>@endif
@if($skill['special'] !== '')<dt>特殊效果</dt><dd class="charge">{{ $skill['special'] }}</dd>@endif
</dl>
@if($skill['twice'])<p class="hint indent">带威力的辅助技能会把能力变化套用两次（原版实际行为，已保留）。</p>@endif
@if($skill['learners'])
<h2 class="sec">可以学习的职业</h2>
<table class="tbl tbl-stack">
<thead><tr><th>职业</th><th>条件</th></tr></thead>
<tbody>@foreach($skill['learners'] as $learner)<tr><td class="primary"><a href="{{ $learner['job']['href'] }}#tree">{{ $learner['job']['name'] }}</a></td><td data-label="条件">{{ $learner['requires'] !== '' ? $learner['requires'] : '无' }}</td></tr>@endforeach</tbody>
</table>
@endif
@if($skill['unlocks'])
<h2 class="sec">学会后可以学习 <span class="sec-aside">{{ count($skill['unlocks']) }} 个</span></h2>
<ul class="item-list indent">@foreach($skill['unlocks'] as $line)<li><x-skill :line="array_replace($line, ['exp' => ''])" /></li>@endforeach</ul>
@endif
@if($skill['users'])
<h2 class="sec">使用该技能的怪物 <span class="sec-aside">{{ count($skill['users']) }} 种</span></h2>
<div class="carpets">@foreach($skill['users'] as $unit)<x-carpet :unit="$unit" />@endforeach</div>
@endif
</div>
@endsection
