@extends('layouts.app')
@section('title','登录')
@section('content')<h4>登录</h4><form method="post" action="{{ route('login') }}">@csrf
<label>ID <input name="login" value="{{ old('login') }}" required maxlength="16" autocomplete="username"></label>
<label>PASS <input type="password" name="password" required maxlength="72" autocomplete="current-password"></label>
<button type="submit">登录</button></form><p><a href="{{ route('register') }}">建立新的冒险队伍</a></p>@endsection
