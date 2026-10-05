@extends('layouts.app')
@section('title', $title)
@section('content')
<h1 class="page-title">{{ $title }}<span class="meta">{{ $simulation ? '不消耗体力' : '每次战斗消耗 1 体力' }} · <a href="{{ route('hunt') }}">返回地图列表</a></span></h1>
@if($simulation)<p class="hint">与镜像队伍战斗，不获得奖励，不保存战斗伤害。</p>@endif
<x-sortie :units="$units" :selected="$selected" :action="$action">
    <x-slot:before><x-sec title="队伍"><x-slot:aside>选择 1–5 名</x-slot:aside></x-sec></x-slot:before>
    @if($enemies)
        <x-sec title="出现敌人" />
        <div class="carpets carpets-5">@foreach($enemies as $enemy)<x-carpet :unit="$enemy" />@endforeach</div>
    @endif
</x-sortie>
@endsection
