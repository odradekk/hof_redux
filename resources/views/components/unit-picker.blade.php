{{--
Props: units (UnitCard arrays), selected (IDs), name, type (checkbox or radio).
Slots: None.
Example: <x-unit-picker :units="$units" :selected="[1]" name="party" />
--}}
@props(['units', 'selected' => [], 'name' => 'party', 'type' => 'checkbox'])
<div {{ $attributes->merge(['class' => 'carpets']) }}>
@foreach($units as $unit)
<label class="pick carpet"><x-carpet :unit="array_diff_key($unit, ['href' => true])">
<x-slot:footer><input type="{{ $type === 'radio' ? 'radio' : 'checkbox' }}" name="{{ $name }}{{ $type === 'radio' ? '' : '[]' }}" value="{{ $unit['id'] }}" aria-label="选择{{ $unit['name'] }}" @checked(in_array((string) $unit['id'], array_map('strval', (array) $selected), true))></x-slot:footer>
</x-carpet></label>
@endforeach
</div>
