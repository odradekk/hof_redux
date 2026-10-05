@extends('layouts.app')
@section('title', '道具')
@section('content')
<x-sec title="所持道具" as="h1" />
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
