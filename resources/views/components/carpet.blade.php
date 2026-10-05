{{--
Props: unit (UnitCard array), href (optional).
Slots: footer.
Example: <x-carpet :unit="$unit" />
--}}
@props(['unit', 'href' => null])
<div {{ $attributes->merge(['class' => 'carpet']) }}>
    @if($href ?? $unit['href'] ?? null)<a href="{{ $href ?? $unit['href'] }}">@endif
    <div @class(['carpet-stage', 'has-base' => !empty($unit['base'])])>
        @if(!empty($unit['base']))<img class="carpet-base" src="{{ asset($unit['base']) }}" alt="" width="134" height="67">@endif
        <img src="{{ asset($unit['img']) }}" alt="" width="{{ $unit['width'] ?? App\Http\View\Images::size($unit['img'])[0] }}" height="{{ $unit['height'] ?? App\Http\View\Images::size($unit['img'])[1] }}">
    </div>
    <span class="carpet-name">{{ $unit['name'] }}@if($unit['star'] ?? false)<span class="star" aria-label="有未分配属性点">*</span>@endif</span>
    @if($href ?? $unit['href'] ?? null)</a>@endif
    <span class="meta">@if(isset($unit['level']))Lv.{{ $unit['level'] }} @endif{{ $unit['label'] ?? '' }}</span>
    @if(!empty($unit['vitals']))<span class="carpet-vitals">HP {{ $unit['vitals']['hp'] }} / {{ $unit['vitals']['maxhp'] }}<br>SP {{ $unit['vitals']['sp'] }} / {{ $unit['vitals']['maxsp'] }}</span>@endif
    {{ $footer ?? '' }}
</div>
