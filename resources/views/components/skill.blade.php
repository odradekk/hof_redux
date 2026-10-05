{{--
Props: line (SkillLine array).
Slots: None.
Example: <x-skill :line="$skill" />
--}}
@props(['line'])
<span {{ $attributes->merge(['class' => 'item']) }}>@if($line['icon'] ?? null)<img class="icon" src="{{ asset($line['icon']) }}" alt="" width="24" height="24">@endif<strong>{{ $line['name'] }}</strong> <span class="support">SP：{{ $line['sp'] }}</span> <span class="charge">学习：{{ $line['learn'] }}点</span></span>
