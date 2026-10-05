@extends('layouts.app')
@section('title','注册')
@section('content')
<x-sec title="新注册" as="h1"/><div class="split split-stack"><form class="form-grid" method="post" action="{{ route('register') }}">@csrf
<x-field label="ID" for="login" hint="4至16位英文字母或数字"><input class="input input-md" name="login" value="{{ old('login') }}" required minlength="4" maxlength="16" autocomplete="username"></x-field>
<x-field label="PASS" for="password" hint="至少12个字符，最多72个UTF-8字节"><input class="input input-md" type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></x-field>
<x-field label="确认密码" for="password_confirmation"><input class="input input-md" type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></x-field>
<div class="full actions"><button class="btn" type="submit">注册</button></div></form><section class="prose"><h2>注册说明</h2><p>账号用于登录，队伍名称和角色名字会在进入游戏后设置。</p><p>请保管好密码，遵守<a href="{{ route('manual') }}">游戏规则</a>，尊重其他冒险者。</p></section></div>
@endsection
