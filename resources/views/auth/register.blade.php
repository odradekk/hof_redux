@extends('layouts.app')
@section('title','注册')
@section('content')<h4>注册</h4><p>Account ID: 4–16 letters or numbers. Password: 12–72 characters.</p><form method="post" action="{{ route('register') }}">@csrf
<label>ID <input name="login" value="{{ old('login') }}" required minlength="4" maxlength="16" autocomplete="username"></label>
<label>PASS <input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<label>确认 PASS <input type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></label>
<button type="submit">注册</button></form>@endsection
