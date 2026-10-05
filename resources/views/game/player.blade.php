@extends('layouts.app')
@section('title', '人材斡旋所')
@section('content')
<x-sec title="人材斡旋所" as="h1"><x-slot:aside>当前队员 {{ $count }} / 5</x-slot:aside></x-sec>
@if($count >= 5)
    <p class="notice">人物上限数达到。请先腾出空位，再雇佣新的同伴。</p>
@else
    <form method="post" action="{{ route('player.command', 'recruit') }}">
        <x-op />
        <div class="recruit-grid">
        @foreach($recruits as $recruit)
            <label class="recruit-card panel">
                <span class="recruit-sprites"><img src="{{ asset($recruit['male']['img']) }}" width="{{ $recruit['male']['size'][0] }}" height="{{ $recruit['male']['size'][1] }}" alt="男性{{ $recruit['male']['name'] }}"><img src="{{ asset($recruit['female']['img']) }}" width="{{ $recruit['female']['size'][0] }}" height="{{ $recruit['female']['size'][1] }}" alt="女性{{ $recruit['female']['name'] }}"></span>
                <span class="recruit-name"><input type="radio" name="base_type" value="{{ $recruit['id'] }}" @checked((int) old('base_type', 1) === $recruit['id']) required> {{ $recruit['male']['name'] }} / {{ $recruit['female']['name'] }}</span>
                <span class="num"><x-money :amount="$recruit['price']" /></span>
            </label>
        @endforeach
        </div>
        <div class="form-grid indent">
            <x-field label="名字" for="recruit-name"><input class="input" id="recruit-name" name="name" value="{{ old('name') }}" maxlength="16" required></x-field>
            <span class="label" id="gender-label">性别</span><div class="choice-row" role="group" aria-labelledby="gender-label">
                <label class="check"><input type="radio" name="gender" value="0" @checked((int) old('gender', 0) === 0)> 男</label>
                <label class="check"><input type="radio" name="gender" value="1" @checked((int) old('gender', 0) === 1)> 女</label>
            </div>
        </div>
        <div class="actions indent"><button class="btn" type="submit">雇佣</button></div>
    </form>
@endif
<x-sec title="同伴" />
<form method="post" action="{{ route('player.command', 'party') }}">
    <x-op />
    <x-unit-picker :units="$units" :selected="$selected" name="characters" type="checkbox" />
    <div class="actions"><button class="btn" type="submit">记住队伍</button><a href="{{ route('home') }}">返回首页</a></div>
</form>
@endsection
