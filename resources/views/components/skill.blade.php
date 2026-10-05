{{--
Props: line (SkillLine array). With "parts" (tone/text pairs from GameText::skillParts) the full
legacy ShowSkillDetail() line is shown instead of SP and learning cost; optional href and exp.
Slots: None.
Example: <x-skill :line="$skill" />
--}}
@props(['line'])
<span {{ $attributes->merge(['class' => 'item']) }}>@if($line['icon'] ?? null)<img class="icon" src="{{ asset($line['icon']) }}" alt="" width="24" height="24">@endif{!! ($line['href'] ?? null) ? '<a class="item-name" href="'.e($line['href']).'">'.e($line['name']).'</a>' : '<strong>'.e($line['name']).'</strong>' !!}
@isset($line['parts'])@foreach($line['parts'] as $part) / <span class="{{ $part['tone'] }}">{{ $part['text'] }}</span>@endforeach @if(($line['exp'] ?? '') !== '')<span class="meta"> / {{ $line['exp'] }}</span>@endif
@else<span class="support">SP：{{ $line['sp'] }}</span> <span class="charge">学习：{{ $line['learn'] }}点</span>@endisset</span>
