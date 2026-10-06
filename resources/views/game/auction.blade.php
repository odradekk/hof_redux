@extends('layouts.app')
@section('title', '拍卖会场')
@section('content')
<x-sec as="h1" title="拍卖(Auction)" />
<x-facility :npc="['img' => 'image/char/ori_003.gif', 'text' => $members ? '欢迎您到拍卖场。请挑选心仪的道具。' : '您有会员卡么？入会后即可出品和竞价。']" :tabs="$tabs">
    <p class="meta">上架费 <x-money :amount="500" /> · 两次上架间隔30秒 · 结束前15分钟内出价将延长至15分钟 · 拍卖不可撤回</p>
    @unless($members)
        <form method="post" action="{{ route('auction.join') }}" class="actions">
            <x-op />
            <button class="btn" type="submit">购买会员卡 · <x-money :amount="\App\Application\Multiplayer\AuctionService::MEMBERSHIP_PRICE" /></button>
        </form>
    @endunless
    <x-sec title="道具拍卖(Item Auction)" as="h2" id="auction-list">
        <x-slot:aside><a href="{{ route('auction', array_filter(['sort' => $sort, 'mine' => $mine ? null : 1])) }}">{{ $mine ? '查看全部' : '只看我的' }}</a></x-slot:aside>
    </x-sec>
    <table class="tbl tbl-stack">
        <thead><tr>
            <th scope="col"><a href="{{ $sortLinks['no']['href'] }}" @if($sortLinks['no']['active']) aria-current="true" @endif>No{{ $sortLinks['no']['arrow'] }}</a></th>
            <th scope="col"><a href="{{ $sortLinks['time']['href'] }}" @if($sortLinks['time']['active']) aria-current="true" @endif>其余{{ $sortLinks['time']['arrow'] }}</a></th>
            <th scope="col"><a href="{{ $sortLinks[$sort === 'price' ? 'rprice' : 'price']['href'] }}" @if(in_array($sort, ['price', 'rprice'], true)) aria-current="true" @endif>价格{{ in_array($sort, ['price', 'rprice'], true) ? $sortLinks[$sort]['arrow'] : '' }}</a></th>
            <th scope="col">道具(Item)</th>
            <th scope="col"><a href="{{ $sortLinks['bid']['href'] }}" @if($sortLinks['bid']['active']) aria-current="true" @endif>Bids{{ $sortLinks['bid']['arrow'] }}</a></th>
            <th scope="col">投标人</th><th scope="col">参展人</th>
        </tr></thead>
        <tbody>
        @forelse($listings as $listing)
            <tr data-id="auction-{{ $listing['id'] }}">
                <td class="c" data-label="No">{{ $listing['id'] }}</td>
                <td data-label="其余"><x-time :at="$listing['ends_at']" mode="relative" :class="$listing['ending_soon'] ? 'dmg' : ''" /></td>
                <td class="num" data-label="价格"><x-money :amount="$listing['price']" /></td>
                <td class="primary">
                    <x-item :line="$listing['line']" />
                    @if($listing['comment'] !== '')<p class="meta">{{ $listing['comment'] }}</p>@endif
                    @if($listing['can_bid'])
                        <form method="post" action="{{ route('auction.bid', $listing['id']) }}" class="actions">
                            <x-op />
                            <input class="input input-sm" type="number" name="price" min="{{ $listing['minimum_bid'] }}" max="1000000000000" value="{{ $listing['minimum_bid'] }}" aria-label="{{ '拍卖'.$listing['id'].'出价金额' }}" required>
                            <button class="btn" type="submit">出价</button>
                        </form>
                    @endif
                </td>
                <td class="c" data-label="Bids">{{ $listing['bid_count'] }}</td>
                <td data-label="投标人">{{ $listing['bidder'] }}</td>
                <td data-label="参展人">{{ $listing['seller'] }}</td>
            </tr>
        @empty
            <tr><td class="primary empty" colspan="7">暂无拍卖物品</td></tr>
        @endforelse
        </tbody>
    </table>
    @if($members)
        <x-sec title="出品" as="h2" id="auction-new" />
        @if($items === [])
            <p class="empty">仓库中没有可拍卖的道具。</p>
        @else
            <form method="post" action="{{ route('auction.exhibit') }}" class="form-grid indent">
                <x-op />
                <x-field label="物品" for="inventory_id">
                    <select class="select" id="inventory_id" name="inventory_id" required>
                        @foreach($items as $item)
                            <option value="{{ $item['id'] }}" @selected((string) old('inventory_id') === (string) $item['id'])>{{ $item['line']['refine'] ? '+'.$item['line']['refine'].' ' : '' }}{{ $item['line']['name'] }} ×{{ $item['line']['qty'] }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="数量" for="quantity"><input class="input input-sm" id="quantity" type="number" name="quantity" min="1" max="1000000" value="{{ old('quantity', 1) }}" required></x-field>
                <x-field label="起始价格" for="price"><input class="input" id="price" type="number" name="price" min="0" max="1000000000000" value="{{ old('price', 0) }}" required></x-field>
                <x-field label="拍卖时长" for="hours"><select class="select" id="hours" name="hours">@foreach([1,3,6,12,18,24] as $hours)<option value="{{ $hours }}" @selected((int) old('hours', 1) === $hours)>{{ $hours }}小时</option>@endforeach</select></x-field>
                <x-field label="描述" for="comment"><input class="input" id="comment" name="comment" maxlength="200" value="{{ old('comment') }}"></x-field>
                <div class="actions"><button class="btn" type="submit">上架 · <x-money :amount="500" /></button></div>
            </form>
        @endif
    @endif
    <x-sec title="拍卖纪录(AuctionLog)" as="h2" id="auction-log" />
    <x-feed :entries="$events" />
</x-facility>
@endsection
