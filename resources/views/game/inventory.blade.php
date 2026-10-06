@extends('layouts.app')
@section('title', '仓库')
@section('content')
<x-sec title="仓库" as="h1"><x-slot:aside>没有容量上限；进入地下城时只能带上背包里的消耗品</x-slot:aside></x-sec>
<x-tabs :items="$tabs" />
@foreach($groups as $key => $group)
    <x-sec :title="$group['label']" :id="'inventory-'.$key" />
    <ul class="item-list indent">
    @forelse($group['items'] as $entry)
        <li data-item-id="{{ $entry['id'] }}">
            @include('game.player-item', ['line' => $entry['line'], 'expanded' => $expanded])
        </li>
    @empty
        <li class="empty">没有这类道具</li>
    @endforelse
    </ul>
@endforeach
@endsection
