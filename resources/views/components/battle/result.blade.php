{{-- Props: result (public result and summary). Slots: none. Example: <x-battle.result :result="$presentation['result']" /> --}}
@props(['result'])
<div {{ $attributes->merge(['class' => 'btl-result']) }}>
    <strong class="{{ $result['winner_side'] === null ? 'draw' : ($result['winner_side'] === 'ally' ? 'recover' : 'dmg') }}">{{ $result['label'] }}</strong>
</div>
<div class="split split-center">
    @foreach($result['summary'] as $side => $summary)
        <div>
            残留HP：<span class="num">{{ is_int($summary['hp']) ? number_format($summary['hp']) : $summary['hp'] }}/{{ is_int($summary['maxhp']) ? number_format($summary['maxhp']) : $summary['maxhp'] }}</span><br>
            存活：{{ $summary['alive'] }}/{{ $summary['total'] }}<br>
            总伤害：{{ is_int($summary['damage']) ? number_format($summary['damage']) : $summary['damage'] }}
            @if($side === 'ally')
                <br>总经验值：{{ number_format($summary['experience']) }}<br>
                金钱：<x-money :amount="$summary['money']" />
                @if($result['items'])
                    <div class="bold">获得战利品：</div>
                    @foreach($result['items'] as $item)
                        <span class="item">@if($item['icon'])<img class="icon" src="{{ asset($item['icon']) }}" width="24" height="24" alt="">@endif{{ $item['name'] }} × {{ number_format($item['quantity']) }}</span>
                    @endforeach
                @endif
            @endif
        </div>
    @endforeach
</div>
@if($result['levelups'])<p class="levelup align-center">{{ implode(' ', $result['levelups']) }}</p>@endif
