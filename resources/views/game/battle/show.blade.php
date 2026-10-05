@extends('layouts.app')
@section('title','战斗记录')
@section('content')
@php
$presentation = $report['presentation'] ?? app(\App\Application\Battle\BattlePresenter::class)->present($report);
$background = $report['background'] ?? 'grass';
$background = in_array($background,['build01','cave','colosseum','grass','grass01','jungle','lava','mount','nest','ocean','pavement01','sand','sea','snow','swamp'],true) ? $background : 'grass';
@endphp
<h2>{{ $report['names'][0] ?? '队伍' }} vs {{ $report['names'][1] ?? '敌人' }}</h2>
<p>{{ ($report['winner'] ?? null) === null ? '平局' : (($report['names'][$report['winner']] ?? '队伍').' 胜利') }} · {{ ['pve'=>'普通战','boss'=>'BOSS 战','pvp'=>'竞技场','simulation'=>'模拟战'][$report['mode'] ?? 'pve'] ?? '战斗' }}@if(in_array($report['reason'] ?? '',['action_limit','step_limit'],true)) · 达到行动上限@endif</p>
<link rel="stylesheet" href="{{ asset('battle.css') }}">
<div class="cards battle-scene battle-background-{{ $background }}">
@foreach($report['teams'] ?? [] as $team)<section class="card battle-team">@foreach($team as $unit)<div class="battle-unit {{ ($unit['state'] ?? 0) === 1 ? 'battle-unit-dead' : '' }}">@if(isset($unit['img']))<img src="{{ asset('image/char/'.basename($unit['img'])) }}" alt="">@endif <strong>{{ $unit['name'] }}</strong> · {{ ($unit['position'] ?? '') === 'front' ? '前排' : '后排' }}<br>@if(isset($unit['hp'])) HP {{ $unit['hp'] }}/{{ $unit['maxhp'] }} · SP {{ $unit['sp'] }}/{{ $unit['maxsp'] }} @else HP ??? @endif</div>@endforeach</section>@endforeach</div>
@if(isset($report['settlement']))<h3>战利品</h3><p>获得 {{ $report['settlement']['money'] }} 金钱，队伍获得 {{ array_sum($report['settlement']['experience']) }} 经验。</p>@foreach($presentation['items'] as $item)<p>{{ $item['name'] }} × {{ $item['quantity'] }}</p>@endforeach @endif
<h3>战斗过程</h3><ol class="battle-events">@foreach($presentation['lines'] as $line)<li class="battle-message-{{ $line['tone'] }}">{{ $line['text'] }}</li>@endforeach</ol>
<p><a href="{{ route('hunt') }}">继续冒险</a> · <a href="{{ route('reports.index') }}">战斗记录</a></p>
@endsection
