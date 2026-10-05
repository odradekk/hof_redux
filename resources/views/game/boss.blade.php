@extends('layouts.app')
@section('title','Union Monster')
@section('content')
<h4>Union Monster · 共享首领</h4><p>每次消耗 10 体力 · 每 20 分钟可挑战一次 · HP/SP 隐藏 · All players share the same boss</p>
@forelse($bosses as $boss)<section><h4>{{ $boss['name'] }}</h4><p>队伍等级上限: {{ $boss['limit'] }} · {{ $boss['alive']?'存活 · Alive':'已击败 · Defeated' }} @if(!$boss['alive']) · Respawns {{ $boss['respawns_at'] }} UTC @endif</p>
@if($boss['alive'])<form method="post" action="{{ route('bosses.challenge',$boss['id']) }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) Str::uuid() }}">@foreach($characters as $character)<label><input type="checkbox" name="party[]" value="{{ $character->id }}">{{ $character->name }} Lv.{{ $character->level }}</label>@endforeach<button>战斗!</button></form>@endif</section>@empty<p>No boss instances initialized.</p>@endforelse
<h4>我的挑战 · My challenges</h4><ul>@foreach($challenges as $challenge)<li><a href="{{ route('multiplayer.report',['boss',$challenge->id]) }}">{{ $challenge->created_at }} · {{ $challenge->killed?'Boss defeated':'Challenge' }}</a></li>@endforeach</ul>
@endsection
