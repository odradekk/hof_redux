@extends('layouts.app')
@section('title', '战斗记录')
@section('content')
@php($presentation = $report['presentation'])
<x-sec :title="$presentation['title']" as="h1">
    <x-slot:aside>{{ $presentation['mode'] }}@isset($createdAt) · <x-time :at="$createdAt" />@endisset</x-slot:aside>
</x-sec>
@if($presentation['limited'])<p class="hint">本次战斗已达到行动上限。</p>@endif
<div class="split split-center">
    @foreach($presentation['header'] as $summary)
        <div>
            <div class="bold">{{ $summary['name'] }}</div>
            总级别：{{ number_format($summary['total_level']) }}<br>
            平均级别：{{ $summary['average_level'] }}<br>
            总HP：<span class="num">{{ is_int($summary['hp']) ? number_format($summary['hp']) : $summary['hp'] }}/{{ is_int($summary['maxhp']) ? number_format($summary['maxhp']) : $summary['maxhp'] }}</span>
        </div>
    @endforeach
</div>
@foreach(array_slice($presentation['segments'], 0, 5) as $segment)
    <x-battle.segment :segment="$segment" :total="count($presentation['segments'])" />
@endforeach
@if(count($presentation['segments']) > 5)
    <details class="more">
        <summary>展开全部战况（还有 {{ count($presentation['segments']) - 5 }} 段）</summary>
        @foreach(array_slice($presentation['segments'], 5) as $segment)
            <x-battle.segment :segment="$segment" :total="count($presentation['segments'])" />
        @endforeach
    </details>
@endif
<x-battle.result :result="$presentation['result']" />
<div class="actions actions-center">
    @if($retry ?? null)
        <form method="post" action="{{ $retry['action'] }}">
            <x-op />
            @foreach($retry['party'] as $id)<input type="hidden" name="party[]" value="{{ $id }}">@endforeach
            <button class="btn" type="submit">再战一次</button>
        </form>
    @endif
    <a href="{{ route('hunt') }}">返回狩猎</a>
    <a href="{{ route('reports.index') }}">战斗记录</a>
</div>
@endsection
