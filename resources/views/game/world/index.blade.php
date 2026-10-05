@extends('layouts.app')
@section('title','冒险')
@section('content')
<h2>普通怪物</h2><p>每次狩猎消耗 1 体力。选择 1–5 名角色。</p>
@foreach($areas as $id=>$area)<p><a href="{{ route('hunt.area',$id) }}">{{ $area['name'] }}</a> · {{ $area['proper'] ?? '' }}</p>@endforeach
<p><a href="{{ url('/bosses') }}">共享 BOSS</a> · <a href="{{ route('simulation') }}">模拟战</a> · <a href="{{ route('reports.index') }}">战斗记录</a></p>
@endsection
