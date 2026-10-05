@extends('layouts.app')
@section('title', '怪物 · '.$monster['name'])
@section('content')
@include('game.information.catalog.head')
<div class="doc">
<h2 class="sec">{{ $monster['name'] }} <span class="sec-aside">@if($monster['union'] && $monster['union']['name'] !== $monster['name']){{ $monster['union']['name'] }} · @endif Lv.{{ $monster['level'] }} · No.{{ $monster['id'] }}</span></h2>
<div class="entry-head">
<div class="carpets"><x-carpet :unit="['name' => $monster['name'], 'img' => $monster['img'], 'width' => $monster['width'], 'height' => $monster['height'], 'base' => $monster['base']]" />
@foreach($monster['variants'] as $variant)@if($variant !== $monster['img'])<x-carpet :unit="['name' => '另一种外观', 'img' => $variant, 'base' => $monster['base']]" />@endif @endforeach</div>
<div>
<dl class="kv">
<dt>等级</dt><dd>{{ $monster['level'] }}</dd>
<dt>生命</dt><dd>{{ $monster['hp'] === null ? '????' : number_format($monster['hp']) }}</dd>
<dt>魔力</dt><dd>{{ $monster['sp'] === null ? '????' : number_format($monster['sp']) }}</dd>
<dt>能力值</dt><dd><ul class="inline-list">@foreach(\App\Application\World\GameText::stats() as $key => $label)<li><b class="meta">{{ $label }}</b> {{ $monster['stats'][$key] }}</li>@endforeach</ul></dd>
<dt>攻击</dt><dd><span class="dmg">物理攻击 {{ $monster['atk'][0] }}</span> / <span class="spdmg">魔法攻击 {{ $monster['atk'][1] }}</span></dd>
<dt>防御</dt><dd><span class="recover">物理防御 {{ $monster['def'][0] }}+{{ $monster['def'][1] }}</span> / <span class="support">魔法防御 {{ $monster['def'][2] }}+{{ $monster['def'][3] }}</span></dd>
<dt>配置</dt><dd>{{ $monster['position'] }} · {{ $monster['guard'] }}</dd>
@if($monster['specials'])<dt>特性</dt><dd>{{ implode('、', $monster['specials']) }}</dd>@endif
@if(! $monster['union'])<dt>奖励</dt><dd>经验 {{ number_format($monster['exp']) }} · 金钱 {{ number_format($monster['money']) }}</dd>@endif
</dl>
</div>
</div>
@if($monster['union'])
@php($union = $monster['union'])
<h2 class="sec">共享首领</h2>
<dl class="kv indent">
<dt>军团</dt><dd>{{ $union['name'] }}</dd>
<dt>挑战限制</dt><dd>出战角色的等级合计不超过 {{ $union['limit'] }}</dd>
<dt>复活时间</dt><dd>{{ $union['cycle'] }}</dd>
<dt>随从</dt><dd>每次挑战随机 {{ $union['amount'] }} 只@if($union['fixed'])，另外固定出现 @foreach($union['fixed'] as $fixed)<a href="{{ $fixed['href'] }}">{{ $fixed['name'] }}</a>{{ $loop->last ? '' : '、' }}@endforeach @endif</dd>
<dt>奖励</dt><dd>每次挑战按造成的伤害获得经验（约每 2 点伤害 1 点经验，由存活角色平分）；击倒首领的队伍再获得击倒奖励和掉落。</dd>
</dl>
<table class="tbl tbl-stack">
<thead><tr><th>随从</th><th>Lv</th><th>每只出现率</th></tr></thead>
<tbody>@foreach($union['minions'] as $minion)<tr><td class="primary">@if($minion['monster']['href'])<a href="{{ $minion['monster']['href'] }}">{{ $minion['monster']['name'] }}</a>@else{{ $minion['monster']['name'] }}@endif</td><td class="num" data-label="Lv">{{ $minion['monster']['level'] }}</td><td class="num" data-label="出现率">{{ $minion['rate'] }}</td></tr>@endforeach</tbody>
</table>
@endif
<h2 class="sec">行动模式</h2>
<p class="meta indent">每次行动从 No.1 开始依次判定，第一条满足的判定执行对应技能；“{{ $data->text()->name('skills', 9000) }}”表示与下一行的判定同时满足才执行。</p>
<table class="tbl sample"><thead><tr><th>No</th><th>判定</th><th>使用技能</th></tr></thead><tbody>
@foreach($monster['pattern'] as $row)<tr><td>{{ $row['no'] }}</td><td>{{ $row['condition'] }}</td><td><x-skill :line="array_replace($row['skill'], ['exp' => ''])" /></td></tr>@endforeach
</tbody></table>
@if($monster['drops'])
<h2 class="sec">掉落 <span class="sec-aside">每只最多掉落 1 件</span></h2>
<table class="tbl tbl-stack">
<thead><tr><th>道具</th><th>掉落率</th></tr></thead>
<tbody>
@foreach($monster['drops'] as $drop)<tr><td class="primary"><x-item :line="$drop['item']" /></td><td class="num" data-label="掉落率">{{ $drop['rate'] }}</td></tr>@endforeach
@if($monster['no_drop'] !== '0%')<tr><td class="primary meta">不掉落</td><td class="num" data-label="概率">{{ $monster['no_drop'] }}</td></tr>@endif
</tbody></table>
@endif
@if($monster['areas'])
<h2 class="sec">出现地图</h2>
<table class="tbl tbl-stack">
<thead><tr><th>地图</th><th>每个敌人位的出现率</th><th>地图页显示</th></tr></thead>
<tbody>@foreach($monster['areas'] as $area)<tr><td class="primary">@if($area['open'])<a href="{{ route('catalog', 'areas') }}#area-{{ $area['id'] }}">{{ $area['name'] }}</a>@else{{ $area['name'] }} <span class="badge">未开放</span>@endif</td><td class="num" data-label="出现率">{{ $area['rate'] }}</td><td data-label="地图页">{{ $area['shown'] ? '显示' : '隐藏（稀有）' }}</td></tr>@endforeach</tbody>
</table>
@endif
@if($monster['summoned_by'])
<h2 class="sec">由以下技能召唤</h2>
<ul class="item-list indent">@foreach($monster['summoned_by'] as $line)<li><x-skill :line="array_replace($line, ['exp' => ''])" /></li>@endforeach</ul>
<p class="meta indent">召唤物的能力按召唤者的敏捷、幸运和召唤力加成放大（“生育”召唤的除外），见 <a href="{{ route('manual', 'advanced') }}#summon">高级指南</a>。</p>
@endif
@if($monster['bosses_of'])
<h2 class="sec">随从于</h2>
<div class="carpets">@foreach($monster['bosses_of'] as $unit)<x-carpet :unit="$unit" />@endforeach</div>
@endif
</div>
@endsection
