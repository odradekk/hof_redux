@extends('layouts.app')
@section('title', '角色')
@section('content')
@include('game.player-nav')
<h2>同伴</h2>
<form method="post" action="{{ route('player.command', 'party') }}">
@include('game.player-token')
<div class="character-grid">
@foreach($characters as $character)
@php($job = $catalog->get('jobs', $character->job_id))
<article class="carpet_frame">
<img src="{{ asset('image/char/'.$job[$character->gender ? 'img_female' : 'img_male']) }}" alt="">
<h3><a href="{{ route('player.character', $character->id) }}">{{ $character->name }}</a></h3>
<p>Lv.{{ $character->level }} {{ $job[$character->gender ? 'name_female' : 'name_male'] }}</p>
<label><input type="checkbox" name="characters[]" value="{{ $character->id }}" @checked(in_array($character->id, auth()->user()->preferences['party'] ?? []))> 编入队伍</label>
</article>

@endforeach

</div>
<button type="submit">记住队伍</button>
</form>
<h2>招募同伴</h2>
<p>队伍上限五人。{{ $characters->count() }} / 5</p>
<form method="post" action="{{ route('player.command', 'recruit') }}">
@include('game.player-token')
<label>职业 <select name="base_type">@foreach($prices as $type => $price)
@php($base = $catalog->get('base_characters', $type))
@php($job = $catalog->get('jobs', $base['job']))
<option value="{{ $type }}">{{ $job['name_male'] }} / {{ $job['name_female'] }} · {{ number_format($price) }} Gold</option>

@endforeach
</select></label>
<label>名字 <input name="name" maxlength="16" required></label>
<label>性别 <select name="gender"><option value="0">男</option><option value="1">女</option></select></label>
<button type="submit" @disabled($characters->count() >= 5)>雇佣</button>
</form>
@endsection
