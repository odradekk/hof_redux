{{--
Props: line (ItemLine array), qty (optional override).
Slots: None.
Example: <x-item :line="$item" />
--}}
@props(['line', 'qty' => null])
<span {{ $attributes->merge(['class' => 'item']) }}>
@if($line['icon'] ?? null)<img class="icon" src="{{ asset($line['icon']) }}" alt="" width="24" height="24">@endif
@if($line['refine'] ?? 0)<strong class="charge">+{{ $line['refine'] }}</strong> @endif<strong>{{ $line['name'] }}</strong> <span class="meta">({{ $line['type'] }})</span>
@if(($qty ?? $line['qty'] ?? 1) > 1) ×{{ $qty ?? $line['qty'] }}@endif
@foreach($line['stats'] ?? [] as $stat)<span class="{{ $stat['tone'] }}">{{ $stat['text'] }}</span>{{ $loop->last ? '' : ' / ' }}@endforeach
@if($line['option'] ?? '')<span class="meta">{{ $line['option'] }}</span>@endif
@if($line['note'] ?? '')<span class="meta">{{ $line['note'] }}</span>@endif
</span>
