{{--
Props: word (required confirmation text).
Slots: None.
Example: <x-confirm word="DELETE" />
--}}
@props(['word'])
<label {{ $attributes->merge(['class' => 'check']) }}>输入 {{ $word }} 确认 <input class="input input-md" name="confirm" required pattern="{{ preg_quote($word, '/') }}" autocomplete="off" aria-label="输入{{ $word }}确认"></label>
