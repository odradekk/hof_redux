{{-- Props: unit (redacted unit snapshot). Slots: none. Example: <x-battle.hpsp :unit="$unit" /> --}}
@props(['unit'])
<div {{ $attributes->class(['hpsp', 'is-dead' => $unit['state'] === 1, 'is-poison' => $unit['state'] === 2]) }}>
    <span class="hpsp-name">{{ $unit['name'] }}</span>
    @if($unit['casting']) <span class="charge">({{ $unit['casting'] }})</span> @endif
    <div class="hpsp-vals">
        <span class="recover">生命：{{ is_int($unit['hp']) ? number_format($unit['hp']) : $unit['hp'] }}/{{ is_int($unit['maxhp']) ? number_format($unit['maxhp']) : $unit['maxhp'] }}</span><br>
        <span class="support">魔力：{{ is_int($unit['sp']) ? number_format($unit['sp']) : $unit['sp'] }}/{{ is_int($unit['maxsp']) ? number_format($unit['maxsp']) : $unit['maxsp'] }}</span>
    </div>
</div>
