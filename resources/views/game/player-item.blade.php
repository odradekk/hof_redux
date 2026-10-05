<x-item :line="array_replace($line, ['option' => '', 'note' => ''])" />
@if($line['option'] || $line['note'])
<details class="more" @if($expanded ?? false) open @endif>
    <summary>道具详情</summary>
    @if($line['option'])<p class="item-option">{{ $line['option'] }}</p>@endif
    @if($line['note'])<p class="meta">{{ $line['note'] }}</p>@endif
</details>
@endif
