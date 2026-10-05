{{--
Props: at (timestamp), mode (short, relative or full).
Slots: None.
Example: <x-time at="2026-10-05T12:00:00Z" />
--}}
@props(['at', 'mode' => 'short'])
@php($time = App\Http\View\Times::present($at, $mode))
<time {{ $attributes }} datetime="{{ $time['iso'] }}" title="{{ $time['title'] }}" @if($mode === 'relative') data-countdown="{{ $time['iso'] }}" @endif>{{ $time['text'] }}</time>
