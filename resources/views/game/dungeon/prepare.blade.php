@extends('layouts.app')
@section('title', $dungeon['name'])
@section('content')
<h1 class="page-title">{{ $dungeon['name'] }}<span class="meta">{{ $dungeon['proper'] }} · {{ $dungeon['rooms'] }} 个房间 · <a href="{{ route('dungeons') }}">返回地下城列表</a></span></h1>
<p class="indent">{{ $dungeon['summary'] }}</p>
<p class="hint indent">每次移动每名成员消耗 {{ $rules['move'] }} 体力，每场战斗再消耗 {{ $rules['battle'] }} 体力。地下城中不会自然恢复 HP 和体力，每次移动按智慧恢复少量 SP。生命降到 0 的同伴会陷入濒死，可以救回或带回城镇。</p>
<form method="post" action="{{ route('dungeons.prepare', $dungeon['id']) }}"><x-op />
    <x-sec title="队伍"><x-slot:aside>选择 1–5 名</x-slot:aside></x-sec>
    <x-unit-picker :units="$units" :selected="$selected" />
    <x-sec title="背包"><x-slot:aside>上限为成员负重之和；每人 {{ $rules['carry'] }} + 力量 ÷ {{ $rules['carry_str'] }}</x-slot:aside></x-sec>
    @if($consumables)
        <table class="tbl tbl-stack">
            <thead><tr><th scope="col">携带</th><th scope="col">持有</th><th scope="col">重量</th><th scope="col">道具</th></tr></thead>
            <tbody>
            @foreach($consumables as $i => $entry)
                <tr>
                    <td data-label="携带"><input type="hidden" name="pack[{{ $i }}][id]" value="{{ $entry['id'] }}"><input class="input input-sm" type="number" name="pack[{{ $i }}][quantity]" min="0" max="{{ min(999, $entry['quantity']) }}" value="{{ old('pack.'.$i.'.quantity', 0) }}" aria-label="{{ $entry['line']['name'] }} 携带数量"></td>
                    <td class="num" data-label="持有">{{ $entry['quantity'] }}</td>
                    <td class="num" data-label="重量">{{ $entry['weight'] }}</td>
                    <td class="primary"><x-item :line="$entry['line']" :qty="1" /></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">仓库中没有消耗品。可以在<a href="{{ route('player.shop') }}">商店</a>购买食物和药水。</p>
    @endif
    <div class="actions actions-center"><button type="submit" class="btn btn-lg">进入地下城</button></div>
</form>
@endsection
