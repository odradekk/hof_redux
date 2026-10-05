@extends('layouts.app')
@section('title','战斗记录')
@section('content')
@php
$unitNames = [];
foreach (array_merge($report['initial_teams'] ?? [], $report['teams'] ?? []) as $team) foreach ($team as $unit) $unitNames[$unit['id']] = $unit['name'];
$eventLabels = ['BattleStarted'=>'战斗开始','ActorSelected'=>'行动','SkillUsed'=>'使用技能','TargetSelected'=>'选择目标','DamageApplied'=>'伤害','ResourceChanged'=>'恢复 / 变化','StatusChanged'=>'状态变化','ActorDied'=>'被打倒','ActorLeveled'=>'升级','Summoned'=>'召唤','BattleFinished'=>'战斗结束','Guarded'=>'保护','CastStarted'=>'开始咏唱','CastInterrupted'=>'咏唱中断','Moved'=>'移动','MagicCirclesChanged'=>'魔法阵变化','StatChanged'=>'属性变化'];
$background = $report['background'] ?? 'grass';
$background = in_array($background,['build01','cave','colosseum','grass','grass01','jungle','lava','mount','nest','ocean','pavement01','sand','sea','snow','swamp'],true) ? $background : 'grass';
@endphp
<h2>{{ $report['names'][0] ?? '队伍' }} vs {{ $report['names'][1] ?? '敌人' }}</h2>
<p>{{ ($report['winner'] ?? null) === null ? '平局' : (($report['names'][$report['winner']] ?? '队伍').' 胜利') }} · {{ $report['mode'] ?? '' }} · {{ $report['reason'] ?? '' }}</p>
<link rel="stylesheet" href="{{ asset('battle.css') }}">
<div class="cards battle-scene battle-background-{{ $background }}">
@foreach($report['teams'] ?? [] as $team)<section class="card battle-team">@foreach($team as $unit)<div class="battle-unit {{ ($unit['state'] ?? 0) === 1 ? 'battle-unit-dead' : '' }}">@if(isset($unit['img']))<img src="{{ asset('image/char/'.basename($unit['img'])) }}" alt="">@endif <strong>{{ $unit['name'] }}</strong> · {{ ($unit['position'] ?? '') === 'front' ? '前排' : '后排' }}<br>@if(isset($unit['hp'])) HP {{ $unit['hp'] }}/{{ $unit['maxhp'] }} · SP {{ $unit['sp'] }}/{{ $unit['maxsp'] }} @else HP ??? @endif</div>@endforeach</section>@endforeach</div>
@if(isset($report['settlement']))<h3>奖励</h3><p>Gold {{ $report['settlement']['money'] }} · XP {{ array_sum($report['settlement']['experience']) }}</p>@foreach($report['settlement']['items'] as $item=>$count)<p>道具 {{ $item }} × {{ $count }}</p>@endforeach @endif
<h3>战斗过程</h3><ol class="battle-events">@foreach($report['events'] ?? [] as $event)<li><strong>{{ $eventLabels[$event['type'] ?? ''] ?? $event['type'] ?? 'Event' }}</strong> @if(isset($event['actor'])){{ $unitNames[$event['actor']] ?? $event['actor'] }}@endif @if(isset($event['target'])) → {{ $unitNames[$event['target']] ?? $event['target'] }}@endif @foreach($event as $key=>$value) @if(!in_array($key,['type','actor','target','sequence','tick'],true) && is_scalar($value))<span>{{ $key }}: {{ $value }}</span> @endif @endforeach</li>@endforeach</ol>
<p>Content {{ $report['content_version'] ?? '' }} · Rules {{ $report['rules_version'] ?? '' }}</p><p><a href="{{ route('hunt') }}">继续冒险</a> · <a href="{{ route('reports.index') }}">战斗记录</a></p>
@endsection
