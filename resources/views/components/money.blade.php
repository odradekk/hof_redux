{{--
Props: amount (integer).
Slots: None.
Example: <x-money :amount="500" />
--}}
@props(['amount'])
<span {{ $attributes->merge(['class' => 'num']) }}>$ {{ number_format((int) $amount) }}</span>
