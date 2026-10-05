{{--
Props: img (local asset path), alt (optional).
Slots: Default: dialogue.
Example: <x-npc img="image/char/ori_002.gif">Welcome</x-npc>
--}}
@props(['img', 'alt' => ''])
<div {{ $attributes->merge(['class' => 'npc']) }}><img src="{{ asset($img) }}" alt="{{ $alt }}" width="{{ App\Http\View\Images::size($img)[0] }}" height="{{ App\Http\View\Images::size($img)[1] }}"><div>{{ $slot }}</div></div>
