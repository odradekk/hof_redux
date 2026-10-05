{{--
Props: title, help (optional), as (h1 or h2).
Slots: aside.
Example: <x-sec title="Overview" as="h1" />
--}}
@props(['title', 'help' => null, 'as' => 'h2'])
@php($tag = in_array($as, ['h1', 'h2'], true) ? $as : 'h2')
<{{ $tag }} {{ $attributes->merge(['class' => 'sec']) }}>{{ $title }}@if($help)<a class="help" href="{{ $help }}" aria-label="{{ $title }}帮助">?</a>@endif @isset($aside)<span class="sec-aside">{{ $aside }}</span>@endisset</{{ $tag }}>
