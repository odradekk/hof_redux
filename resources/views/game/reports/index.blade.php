@extends('layouts.app')
@section('title', '战斗记录')
@section('content')
<x-sec title="战斗记录" as="h1" />
<x-tabs :items="$tabs" />
@if($entries)
    <ol class="feed">@foreach($entries as $entry)<x-battle.record :record="$entry" />@endforeach</ol>
@else
    <p class="empty">暂无记录。</p>
@endif
{{ $records->withQueryString()->links() }}
@endsection
