@extends('layouts.app')
@section('title', $mode === 'refine' ? '精炼工房' : '制作工房')
@section('content')
<x-sec :title="$mode === 'refine' ? '精炼工房' : '制作工房'" as="h1" />
<x-facility :npc="$npc" :tabs="$tabs">
@if($mode === 'refine')
    <x-sec title="精炼道具" />
    <p class="hint indent">每次费用为基础买价的一半（四舍五入）。失败会毁掉道具，最高 +10；资金不足时保留已完成的精炼。</p>
    @if($refinable)
    <form method="post" action="{{ route('player.command', 'refine') }}" data-confirm="精炼失败会毁掉所选道具，确定继续吗？">
        <x-op />
        <ul class="item-list indent">
        @foreach($refinable as $entry)<li><label class="check"><input type="radio" name="inventory_id" value="{{ $entry['id'] }}" @checked((string) old('inventory_id') === (string) $entry['id']) required><x-item :line="$entry['line']" /><span class="meta">每次 <x-money :amount="$entry['cost']" /></span></label></li>@endforeach
        </ul>
        <div class="form-grid indent"><x-field label="次数" for="refine-times"><input class="input input-sm" id="refine-times" type="number" name="times" value="{{ old('times', 1) }}" min="1" max="10" required></x-field></div>
        <div class="actions indent"><button class="btn" type="submit">精炼</button></div>
    </form>
    @else<p class="empty indent">没有可精炼的道具</p>@endif
    <x-sec title="精炼成功率" />
    <table class="tbl"><thead><tr><th scope="col">目标等级</th><th scope="col">成功率</th></tr></thead><tbody>
        <tr><th scope="row">+1–+4</th><td class="num">100%</td></tr><tr><th scope="row">+5</th><td class="num">60%</td></tr>
        <tr><th scope="row">+6–+7</th><td class="num">40%</td></tr><tr><th scope="row">+8–+9</th><td class="num">20%</td></tr><tr><th scope="row">+10</th><td class="num">10%</td></tr>
    </tbody></table>
    @if(session('player_result.attempts'))
        <x-sec title="精炼结果" /><ol>
        @foreach(session('player_result.attempts') as $attempt)<li class="{{ $attempt['success'] ? 'support' : 'dmg' }}">+{{ $attempt['from'] }} → +{{ $attempt['to'] }} {{ $attempt['success'] ? '成功' : '失败' }} · <x-money :amount="$attempt['cost']" /></li>@endforeach
        </ol>
    @endif
@else
    <x-sec title="道具制作" />
    <p class="hint indent">制作费用为零。配方消耗未精炼、无附加能力的材料；制作结果随机获得附加能力，特殊材料可再追加一种属性。</p>
    <form method="post" action="{{ route('player.command', 'craft') }}">
        <x-op />
        <table class="tbl tbl-stack"><thead><tr><th scope="col">选择</th><th scope="col">成品</th><th scope="col">材料（需要 / 持有）</th></tr></thead><tbody>
        @foreach($recipes as $recipe)<tr>
            <td data-label="选择"><input type="radio" name="item_id" value="{{ $recipe['id'] }}" aria-label="制作 {{ $recipe['line']['name'] }}" @checked((string) old('item_id') === (string) $recipe['id']) required></td>
            <td class="primary"><x-item :line="$recipe['line']" /></td>
            <td data-label="材料"><ul class="item-list">@foreach($recipe['ingredients'] as $ingredient)<li><x-item :line="$ingredient['line']" :qty="$ingredient['need']" /><span class="{{ $ingredient['owned'] < $ingredient['need'] ? 'dmg' : 'meta' }}">（持有 {{ $ingredient['owned'] }}）</span></li>@endforeach</ul></td>
        </tr>@endforeach
        </tbody></table>
        <div class="form-grid indent"><x-field label="追加材料" for="craft-material"><select class="select" id="craft-material" name="material"><option value="">不追加</option>@foreach($materials as $material)<option value="{{ $material['id'] }}" @selected((string) old('material') === (string) $material['id'])>{{ $material['name'] }} ×{{ $material['quantity'] }}</option>@endforeach</select></x-field></div>
        <div class="actions indent"><button class="btn" type="submit">制作</button></div>
    </form>
    <x-sec title="所持素材与道具" id="materials" />
    <ul class="item-list indent">@forelse($items as $entry)<li><x-item :line="$entry['line']" /></li>@empty<li class="empty">没有素材与道具</li>@endforelse</ul>
@endif
</x-facility>
@endsection
