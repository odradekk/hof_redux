{{-- Sprite on a carpet, or on a land tile when $land is given (legacy ShowCharWithLand). $unit: monsterLine()/jobLine(); optional $caption. --}}
<li class="unit">
<div class="carpet-stage{{ !empty($land) ? ' has-base' : '' }}">@if(!empty($land))<img class="carpet-base" src="{{ asset('image/other/land_'.$land.'.gif') }}" width="134" height="67" alt="">@endif<img src="{{ asset($unit['img']) }}" alt="{{ $unit['name'] }}" loading="lazy"></div>
@if($unit['href'] ?? null)<a class="unit-name" href="{{ $unit['href'] }}">{{ $unit['name'] }}</a>@else<span class="unit-name">{{ $unit['name'] }}</span>@endif
@isset($caption)<span class="meta">{{ $caption }}</span>@endisset
</li>
