{{-- Props: record (safe list summary, href, at). Slots: none. Example: <x-battle.record :record="$entry" /> --}}
@props(['record'])
<li {{ $attributes }}>
    <x-time :at="$record['at']" />
    <a href="{{ $record['href'] }}">战斗 {{ number_format($record['actions']) }} 回合 [{{ $record['result'] }}]</a>
    @foreach($record['participants'] as $participant)
        @unless($loop->first) vs @endunless
        <span class="{{ $participant['tone'] }}">{{ $participant['name'] }}</span><span class="meta">({{ $participant['count'] }}人:平均{{ $participant['average_level'] }}级)</span>
    @endforeach
</li>
