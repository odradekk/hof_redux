{{-- Props: action (side, actor, optional skill, lines). Slots: none. Example: <x-battle.action :action="$action" /> --}}
@props(['action'])
<li {{ $attributes->merge(['class' => 'btl-row '.$action['side']]) }}>
    <div class="act">
        <div class="act-head">{{ $action['actor'] }}@if($action['skill'])@if($action['skill']['icon'])<img class="icon" src="{{ asset($action['skill']['icon']) }}" width="24" height="24" alt="">@endif{{ $action['skill']['name'] }}@endif</div>
        @foreach($action['lines'] as $line)
            <p>@if($line['icon'] ?? null)<img class="icon" src="{{ asset($line['icon']) }}" width="24" height="24" alt="">@endif<span class="{{ $line['class'] }}">{{ $line['text'] }}</span>@if($line['change']) <span class="light">{{ $line['change'] }}</span>@endif</p>
        @endforeach
    </div>
</li>
