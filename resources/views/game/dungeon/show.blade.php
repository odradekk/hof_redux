@extends('layouts.app')
@section('title', $name)
@section('content')
<h1 class="page-title">{{ $name }}<span class="meta">{{ $status }} · 共 {{ $steps }} 步@if($money) · 带回 <x-money :amount="$money" />@endif</span></h1>
@if($active)<p class="notice indent">探索仍在进行。<a href="{{ route('dungeon') }}">继续探索</a></p>@endif
<x-sec title="队伍" />
<ul class="inline-list indent">@foreach($party as $member)<li>{{ $member['name'] }}@if($member['fallen']) <span class="dmg">阵亡</span>@endif</li>@endforeach</ul>
<x-sec title="探索日志" />
<ol class="feed">
@foreach($events as $event)
    <li>{{ $event['text'] }}@if($event['report']) <a href="{{ $event['report'] }}">战报</a>@endif <x-time :at="$event['at']" /></li>
@endforeach
</ol>
<p class="actions"><a class="btn" href="{{ route('dungeons') }}">返回地下城列表</a> <a class="btn" href="{{ route('home') }}">回到城镇</a></p>
@endsection
