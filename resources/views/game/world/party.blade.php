@extends('layouts.app')
@section('title',$simulation ? '模拟战' : $area['name'])
@section('content')
<h2>{{ $simulation ? '模拟战' : $area['name'] }}</h2>
<p>{{ $simulation ? '与镜像队伍战斗，不消耗体力，不获得奖励，不保存战斗伤害。' : '战斗消耗 1 体力。击败敌人可获得金钱、经验和道具。' }}</p>
<form method="post" action="{{ $simulation ? route('simulation') : route('hunt.area',$areaId) }}">@csrf
<input type="hidden" name="operation_id" value="{{ (string) Str::uuid() }}">
<fieldset><legend>队伍（1–5 名角色）</legend>
@foreach($characters as $character)<label class="card"><input type="checkbox" name="party[]" value="{{ $character->id }}" @checked(in_array($character->id,auth()->user()->preferences['party'] ?? []))> {{ $character->name }} · Lv {{ $character->level }} · {{ $character->position }}</label>@endforeach
</fieldset><label><input type="checkbox" name="remember" value="1"> 保存此队伍</label> <button type="submit">战斗!</button></form>
@if(count($enemies))<h3>出现敌人</h3><div class="cards">@foreach($enemies as $id=>$enemy)<article class="card">@if(isset($enemy['img']))<img src="{{ asset('image/char/'.basename($enemy['img'])) }}" alt="">@endif <strong>{{ $enemy['name'] }}</strong> · Lv {{ $enemy['level'] ?? '?' }}</article>@endforeach</div>@endif
@endsection
