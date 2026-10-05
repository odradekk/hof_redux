{{--
Props: npc (img/text/alt array), tabs (navigation arrays).
Slots: Default: facility sections.
Example: <x-facility :npc="$npc" :tabs="$tabs">Content</x-facility>
--}}
@props(['npc', 'tabs' => []])
<section {{ $attributes }}><x-npc :img="$npc['img']" :alt="$npc['alt'] ?? ''"><p>{{ $npc['text'] ?? '' }}</p><x-tabs :items="$tabs"/></x-npc>{{ $slot }}</section>
