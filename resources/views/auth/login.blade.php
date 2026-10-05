@extends('layouts.app')
@section('title','登录')
@section('content')
<div class="landing"><section class="landing-art"><img src="{{ asset('image/top01.gif') }}" alt="" width="334" height="138"><dl class="landing-intro"><dt>这到底是什么游戏?</dt><dd>游戏的目的是得到第一，并且保持住第一的位置。</dd><dd>虽然没有冒险的要素，但有点深奥的战斗系统。</dd><dt>战斗的感觉是什么?</dt><dd>最多5人组成队伍。</dd><dd>各人物各持不同模式，根据战斗的状况来使用技能。</dd><dd><a class="a0" href="{{ route('reports.index') }}">这边</a>可以回览战斗记录。</dd></dl></section>
<section class="landing-login"><x-sec title="登录" as="h1"/><form class="form-grid indent" method="post" action="{{ route('login') }}">@csrf
<x-field label="ID" for="login"><input class="input input-md" name="login" value="{{ old('login') }}" required maxlength="16" autocomplete="username"></x-field>
<x-field label="PASS" for="password"><input class="input input-md" type="password" name="password" required maxlength="72" autocomplete="current-password"></x-field>
<div class="full actions"><button class="btn" type="submit">登录</button><a href="{{ route('register') }}">新注册</a></div></form>
<x-sec title="排行榜"/><table class="tbl rank"><thead><tr><th scope="col">排名</th><th scope="col">队伍</th></tr></thead><tbody>@forelse($ranking as $entry)<tr><td>@if($entry['position'] <= 3)<img src="{{ asset('image/icon/crown0'.$entry['position'].'.png') }}" alt="" width="20" height="20">@endif{{ $entry['position'] }}位</td><td>{{ $entry['name'] }}<br><span class="meta">{{ $entry['total'] }}战 {{ $entry['wins'] }}胜{{ $entry['losses'] }}败 {{ $entry['draws'] }}引 {{ $entry['defenses'] }}防 胜率{{ $entry['rate'] }}%</span></td></tr>@empty<tr><td colspan="2">暂无排行</td></tr>@endforelse</tbody></table></section></div>
<x-sec title="提示"/><p class="indent">现在有 {{ number_format($userCount) }} 支队伍在这里冒险。<a href="{{ route('manual') }}">请先阅读游戏规则和手册</a></p>
@endsection
