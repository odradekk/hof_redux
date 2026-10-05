@extends('layouts.app')
@section('content')
@if($tutorial)<p class="hint indent"><a href="{{ route('manual', ['section'=>'tutorial']) }}">教程</a> - 战斗的基本（注册后一小时内显示）</p>@endif
<x-sec :title="$teamName" as="h1"><x-slot:aside><a href="{{ route('player.roster') }}">招募同伴</a></x-slot:aside></x-sec>
<div class="carpets carpets-5">@foreach($cards as $card)<x-carpet :unit="$card"/>@endforeach</div>
@endsection
