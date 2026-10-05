@extends('game.information.layout')
@section('title', '游戏资料 · 职业')
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">职业(Job)</h2>
<ul class="toc">
@foreach($jobs as $job)
@if(! $job['from'])
<li><a href="#job-{{ $job['id'] }}">{{ $job['name'] }}</a>
<ul>@foreach($job['to'] as $to)<li><a href="#job-{{ $to['job']['id'] }}">{{ $to['job']['name'] }}</a> <span class="meta">Lv.{{ $to['level'] }}</span></li>@endforeach</ul></li>
@endif
@endforeach
</ul>
<p class="meta indent">生命 / 魔力系数代入公式：最大生命 = 100 × 生命系数 × (1 + (等级 − 1) ÷ 49) × (1 + (255² − (255 − 力量)²) ÷ 255²)，最大魔力把力量换成智慧。详见 <a href="{{ route('catalog', 'rules') }}#vitals">数值规则</a>。</p>
@foreach($jobs as $job)
<h2 class="sec" id="job-{{ $job['id'] }}"><a href="{{ $job['href'] }}">{{ $job['name'] }}</a>@if($job['name_female'] !== $job['name']) / {{ $job['name_female'] }}@endif <span class="sec-aside">{{ $job['from'] ? '高级职业' : '基本职业' }} · <a href="{{ $job['href'] }}">技能树 →</a></span></h2>
<div class="entry-head">
<ul class="units">@foreach($job['images'] as $i => $image)@include('game.information.unit', ['unit' => ['name' => $i ? '女性' : '男性', 'img' => $image['img']]])@endforeach</ul>
<div>
<p>{{ $job['note'] }}</p>
<dl class="kv">
<dt>可装备</dt><dd>{{ implode('、', $job['equip']) }}</dd>
<dt>生命系数</dt><dd>× {{ $job['coe'][0] }}</dd>
<dt>魔力系数</dt><dd>× {{ $job['coe'][1] }}</dd>
@if($job['from'])<dt>转职条件</dt><dd><a href="{{ $job['from']['job']['href'] }}">{{ $job['from']['job']['name'] }}</a> 等级 {{ $job['from']['level'] }} 以上</dd>@endif
@if($job['to'])<dt>可转职为</dt><dd>@foreach($job['to'] as $to)<a href="{{ $to['job']['href'] }}">{{ $to['job']['name'] }}</a>（Lv.{{ $to['level'] }}）{{ $loop->last ? '' : '、' }}@endforeach</dd>@endif
@if($job['starter'])<dt>雇佣费用</dt><dd>{{ \App\Application\World\GameText::money($job['starter']['price']) }}</dd>@endif
</dl>
</div>
</div>
@endforeach
@endsection
