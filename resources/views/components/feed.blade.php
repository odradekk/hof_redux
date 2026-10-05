{{--
Props: entries (who/text/at/tone/href/color arrays).
Slots: None.
Example: <x-feed :entries="$entries" />
--}}
@props(['entries'])
<ul {{ $attributes->merge(['class' => 'feed']) }}>@forelse($entries as $entry)<li>
@if($entry['who'] ?? '')<span @class(['who', 'uc-'.strtolower($entry['color'] ?? '') => !empty($entry['color']) && App\Http\View\UiColors::valid($entry['color'])])>{{ $entry['who'] }}</span> &gt; @endif
@if($entry['href'] ?? null)<a href="{{ $entry['href'] }}">@endif<span class="{{ in_array($entry['tone'] ?? '', ['dmg','recover','support','charge','light'], true) ? $entry['tone'] : '' }}">{{ $entry['text'] }}</span>@if($entry['href'] ?? null)</a>@endif
@if($entry['at'] ?? null)(<x-time :at="$entry['at']"/>)@endif</li>@empty<li class="empty">暂无记录</li>@endforelse</ul>
