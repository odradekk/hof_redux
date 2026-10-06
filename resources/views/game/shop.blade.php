@extends('layouts.app')
@section('title', '店')
@section('content')
<x-sec title="店" as="h1" />
<x-facility :npc="$npc" :tabs="$tabs">
@if($mode === 'work')
    <x-sec title="打工" />
    <p class="indent">派一名体力满 100 的角色打工：消耗其 100 体力 → <x-money :amount="500" />。角色在城镇中每天恢复 500 体力，上限 100。</p>
    <form method="post" action="{{ route('player.command', 'work') }}">
        <x-op />
        <ul class="item-list indent">
        @foreach($workers as $worker)
            <li><label class="check"><input type="radio" name="character_id" value="{{ $worker['id'] }}" @checked((int) old('character_id') === $worker['id']) @disabled($worker['stamina'] < 100) required>{{ $worker['name'] }} <span class="meta">体力 {{ $worker['stamina'] }} / 100</span></label></li>
        @endforeach
        </ul>
        @php($ready = collect($workers)->contains(fn ($worker) => $worker['stamina'] >= 100))
        <div class="actions indent"><button class="btn btn-lg" type="submit" @disabled(! $ready)>打工</button>
            @unless($ready)<span class="hint">没有体力满 100 的角色，体力恢复到 100 后才能打工</span>@endunless
        </div>
    </form>
@else
    <x-sec :title="$mode === 'buy' ? '购买' : '出售'"><x-slot:aside>勾选并填写数量</x-slot:aside></x-sec>
    @if($inventory?->hasPages())<p class="hint">每页最多显示 100 项，仅出售本页勾选的道具。切换页面前请先完成出售。</p>@endif
    <form method="post" action="{{ route('player.command', $mode) }}">
        <x-op /><input type="hidden" name="selection_mode" value="checked">
        <table class="tbl tbl-stack">
            <thead><tr><th scope="col">选择</th><th scope="col">{{ $mode === 'buy' ? '价格' : '卖价' }}</th>@if($mode === 'sell')<th scope="col">持有</th>@endif<th scope="col">数</th><th scope="col">道具</th></tr></thead>
            <tbody>
            @forelse($items as $i => $entry)
                <tr>
                    <td class="c" data-label="选择"><input type="hidden" name="items[{{ $i }}][id]" value="{{ $entry['id'] }}"><input type="hidden" name="items[{{ $i }}][on]" value="0"><input type="checkbox" name="items[{{ $i }}][on]" value="1" @checked(old('items.'.$i.'.on', false)) aria-label="选择 {{ $entry['line']['name'] }}"></td>
                    <td class="num" data-label="{{ $mode === 'buy' ? '价格' : '卖价' }}"><x-money :amount="$entry['price']" /></td>
                    @if($mode === 'sell')<td class="num" data-label="持有">{{ $entry['quantity'] }}</td>@endif
                    <td data-label="数量"><input class="input input-sm" aria-label="{{ $entry['line']['name'] }} 数量" name="items[{{ $i }}][quantity]" type="number" min="1" max="{{ min(999, $entry['quantity']) }}" value="{{ old('items.'.$i.'.quantity', 1) }}" required></td>
                    <td class="primary"><x-item :line="$entry['line']" :qty="1" /></td>
                </tr>
            @empty
                <tr><td class="primary" colspan="5">没有可出售的道具</td></tr>
            @endforelse
            </tbody>
        </table>
        <input type="hidden" name="form_complete" value="1">
        <div class="actions"><button class="btn" type="submit" @disabled(! $items)>{{ $mode === 'buy' ? '买' : '卖' }}</button><span class="hint">合计以服务器结算为准</span></div>
    </form>
    @if($inventory){{ $inventory->links() }}@endif
@endif
</x-facility>
@endsection
