@extends('layouts.app')
@section('content')<h4>队伍名称</h4><form method="post" action="{{ route('setup') }}">@csrf
<label>Team name <input name="name" value="{{ old('name') }}" required maxlength="16"></label><h4>第一个角色</h4>
<label>Character name <input name="character_name" value="{{ old('character_name') }}" required maxlength="16"></label>
<label>职业 <select name="base_type"><option value="1">战士 / Warrior</option><option value="2">巫师 / Sorcerer</option></select></label>
<label>性别 <select name="gender"><option value="0">Male</option><option value="1">Female</option></select></label><button type="submit">开始冒险</button></form>@endsection
