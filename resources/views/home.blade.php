@extends('layouts.app')
@section('content')
<h4>{{ auth()->user()->name }}</h4>
<div class="character-grid">
@foreach($characters as $character)
@php
    $job = $jobs[$character->job_id];
    $gender = $character->gender === 1 ? 'female' : 'male';
    $jobName = $job['name_'.$gender];
@endphp
<article class="character-card">
    <a href="{{ url('/characters/'.$character->id) }}"><img src="{{ asset('image/char/'.basename($job['img_'.$gender])) }}" alt="{{ $character->name }} · {{ $jobName }}"></a>
    <h3><a href="{{ url('/characters/'.$character->id) }}">{{ $character->name }}</a></h3>
    <p>Lv. {{ $character->level }} · {{ $jobName }}</p>
    <p>HP {{ $character->stats['hp'] }} / {{ $character->stats['maxhp'] }}<br>SP {{ $character->stats['sp'] }} / {{ $character->stats['maxsp'] }}</p>
    <p>STR {{ $character->stats['str'] }} / INT {{ $character->stats['int'] }} / DEX {{ $character->stats['dex'] }} / SPD {{ $character->stats['spd'] }} / LUK {{ $character->stats['luk'] }}</p>
</article>
@endforeach
</div>
<p><a href="{{ url('/hunt') }}">冒险</a> · <a href="{{ url('/town') }}">进入城镇</a></p>
@endsection
