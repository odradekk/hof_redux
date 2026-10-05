{{-- Props: segment (public snapshot and actions), total (segment count). Slots: none. Example: <x-battle.segment :segment="$segment" :total="3" /> --}}
@props(['segment', 'total'])
<section {{ $attributes }} aria-label="第 {{ $segment['index'] + 1 }} 段战况">
    <div class="btl-img" id="s{{ $segment['index'] + 1 }}">
        <x-battle.stage :snapshot="$segment" />
        <nav class="btl-jump" aria-label="战况跳转">
            @if($segment['index'] > 0)<a href="#s{{ $segment['index'] }}" aria-label="上一段战况">&laquo;</a>@else<span class="light" aria-hidden="true">&laquo;</span>@endif
            @if($segment['index'] + 1 < $total)<a href="#s{{ $segment['index'] + 2 }}" aria-label="下一段战况">&raquo;</a>@else<span class="light" aria-hidden="true">&raquo;</span>@endif
        </nav>
    </div>
    <div class="btl-state split">
        @foreach($segment['units'] as $side => $rows)
            <div role="group" aria-label="{{ $side === 'foe' ? '对手' : '我方' }}状态">
                @foreach($rows as $position => $units)
                    <div @if(count($units)) role="group" aria-label="{{ $position === 'front' ? '前卫' : '后卫' }}" @else aria-hidden="true" @endif>
                        @foreach($units as $unit)<x-battle.hpsp :unit="$unit" />@endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    <ol class="btl-log" start="{{ $segment['action'] + 1 }}">
        @foreach($segment['actions'] as $action)<x-battle.action :action="$action" />@endforeach
    </ol>
</section>
