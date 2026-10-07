@extends('layouts.app')
@section('title', '初始设置')
@section('content')
<form method="post" action="{{ route('setup') }}">@csrf
@if($returning)
<x-sec title="重新出发" as="h1"/><p class="indent">队伍的成员都已阵亡。城镇的人材斡旋所愿意免费介绍一名新同伴。</p>
<x-sec title="新的同伴"/>
@else
<x-sec title="队伍名称" as="h1"/><div class="form-grid indent"><x-field label="队伍名" for="name"><input class="input input-lg" name="name" maxlength="16" value="{{ old('name') }}" required></x-field></div>
<x-sec title="第一个角色"/>
@endif<div class="recruit-grid">@foreach($choices as $choice)<label class="recruit-card panel"><span class="recruit-sprites"><img src="{{ asset($choice['male']['img']) }}" alt="男性{{ $choice['male']['name'] }}" width="{{ App\Http\View\Images::size($choice['male']['img'])[0] }}" height="{{ App\Http\View\Images::size($choice['male']['img'])[1] }}"><img src="{{ asset($choice['female']['img']) }}" alt="女性{{ $choice['female']['name'] }}" width="{{ App\Http\View\Images::size($choice['female']['img'])[0] }}" height="{{ App\Http\View\Images::size($choice['female']['img'])[1] }}"></span><span class="recruit-name"><input type="radio" name="base_type" value="{{ $choice['id'] }}" @checked((string) old('base_type', 1) === (string) $choice['id']) required> {{ $choice['male']['name'] }}</span></label>@endforeach</div>
<div class="form-grid indent"><x-field label="角色名字" for="character_name"><input class="input input-md" name="character_name" maxlength="16" value="{{ old('character_name') }}" required></x-field><span class="label">性别</span><div class="choice-row"><label class="check"><input type="radio" name="gender" value="0" @checked((string) old('gender', 0) === '0')>男</label><label class="check"><input type="radio" name="gender" value="1" @checked((string) old('gender', 0) === '1')>女</label></div></div>
<div class="actions actions-center"><button class="btn btn-lg" type="submit">开始冒险</button></div></form>
@endsection
