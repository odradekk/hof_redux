@extends('layouts.app')
@section('title', '商店')
@section('content')
@include('game.player-nav')
<h2>道具商店</h2>
<p>Gold: {{ number_format(auth()->user()->money) }}</p>
<form method="post" action="{{ route('player.command', 'buy') }}">
@include('game.player-token')
<table><thead><tr><th>道具</th><th>单价</th><th>购买数量</th></tr></thead><tbody>
@foreach($stock as $id => $data)<tr><td>@include('game.player-item')</td><td>{{ number_format($data['buy']) }}</td><td><input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $id }}"><input aria-label="{{ $data['name'] }} 数量" name="items[{{ $loop->index }}][quantity]" type="number" min="0" max="999" value="0"></td></tr>
@endforeach

</tbody></table><button>购买选中道具</button>
</form>
<h2>出售道具</h2>
<form method="post" action="{{ route('player.command', 'sell') }}">
@include('game.player-token')
<table><thead><tr><th>道具</th><th>卖价</th><th>持有</th><th>出售数量</th></tr></thead><tbody>
@forelse($items as $entry)<tr><td>@include('game.player-item', ['data' => $entry['data']])</td><td>{{ number_format($entry['data']['sell_price']) }}</td><td>{{ $entry['row']->quantity }}</td><td><input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $entry['row']->id }}"><input aria-label="{{ $entry['data']['name'] }} 出售数量" name="items[{{ $loop->index }}][quantity]" type="number" min="0" max="{{ min(999, $entry['row']->quantity) }}" value="0"></td></tr>
@empty
<tr><td colspan="4">没有可出售的道具</td></tr>
@endforelse

</tbody></table><button>出售选中道具</button></form>
<h2>工作</h2><p>100 体力 → 500 Gold。体力每天恢复 500，上限 100。</p>
<form method="post" action="{{ route('player.command', 'work') }}">@include('game.player-token')<button>打工</button></form>
@endsection
