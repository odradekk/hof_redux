{{-- Props: snapshot (public segment). Slots: none. Example: <x-battle.stage :snapshot="$segment" /> --}}
@props(['snapshot'])
<svg {{ $attributes->merge(['class' => 'stage']) }} viewBox="0 0 480 200" role="img" aria-label="{{ $snapshot['label'] }}">
    <image href="{{ asset($snapshot['stage']['background']) }}" x="0" y="0" width="480" height="200" />
    @foreach($snapshot['stage']['circles'] as $circle)
        <image href="{{ asset($circle['src']) }}" x="{{ $circle['x'] }}" y="{{ $circle['y'] }}" width="{{ $circle['width'] }}" height="{{ $circle['height'] }}" />
    @endforeach
    @foreach($snapshot['stage']['sprites'] as $sprite)
        <image href="{{ asset($sprite['src']) }}" x="{{ $sprite['x'] }}" y="{{ $sprite['y'] }}" width="{{ $sprite['width'] }}" height="{{ $sprite['height'] }}" @if($sprite['transform']) transform="{{ $sprite['transform'] }}" @endif />
    @endforeach
</svg>
