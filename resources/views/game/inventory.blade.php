@extends('layouts.app')
@section('title', '道具')
@section('content')
@include('game.player-nav')
<h2>所持道具</h2>
<form method="get"><label>类型 <select name="type"><option value="">全部</option>@foreach($types as $option)<option @selected($type === $option)>{{ $option }}</option>
@endforeach
</select></label><button>显示</button></form>
<table><thead><tr><th>道具</th><th>数量</th></tr></thead><tbody>
@forelse($items as $entry)<tr><td>@include('game.player-item', ['data' => $entry['data']])</td><td>{{ $entry['row']->quantity }}</td></tr>
@empty
<tr><td colspan="2">没有道具</td></tr>
@endforelse

</tbody></table>
@endsection
