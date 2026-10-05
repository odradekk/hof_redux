{{--
Props: rows (label/value/plus arrays or label-value pairs).
Slots: None.
Example: <x-kv :rows="$rows" />
--}}
@props(['rows'])
<dl {{ $attributes->merge(['class' => 'kv']) }}>@foreach($rows as $key => $row)<dt>{{ is_array($row) ? $row['label'] : $key }}</dt><dd>{{ is_array($row) ? $row['value'] : $row }}@if(is_array($row) && !empty($row['plus'])) <span class="plus">{{ $row['plus'] > 0 ? '+' : '' }} {{ $row['plus'] }}</span>@endif</dd>@endforeach</dl>
