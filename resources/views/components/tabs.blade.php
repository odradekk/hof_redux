{{--
Props: items (label, href, active arrays).
Slots: None.
Example: <x-tabs :items="$tabs" />
--}}
@props(['items'])
<nav {{ $attributes }} aria-label="页面分类"><ul class="tabs">@foreach($items as $item)<li><a href="{{ $item['href'] }}" @if($item['active'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a></li>@endforeach</ul></nav>
