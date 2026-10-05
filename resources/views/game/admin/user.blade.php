@extends('layouts.app')
@section('title', '账号管理')
@section('content')
<x-sec as="h1" :title="'#'.$player->id.' · '.$player->login.' · '.$player->name">
    <x-slot:aside><a href="{{ route('admin.index') }}">返回管理</a></x-slot:aside>
</x-sec>
<p>资金 <x-money :amount="$player->money" /></p>
<p class="meta">注册于 <x-time :at="$player->created_at" mode="full" /> · 最近登录 @if($player->last_login_at)<x-time :at="$player->last_login_at" mode="full" />@else尚未登录@endif</p>
<x-sec title="角色" as="h2" />
<div class="carpets">@forelse($units as $unit)<x-carpet :unit="$unit" />@empty<p class="empty">暂无角色</p>@endforelse</div>
<x-sec title="道具" as="h2" />
<table class="tbl tbl-stack">
    <thead><tr><th scope="col">道具</th><th scope="col">编号</th><th scope="col">位置</th></tr></thead>
    <tbody>@forelse($items as $item)<tr><td class="primary"><x-item :line="$item['line']" /></td><td data-label="编号">{{ $item['id'] }}</td><td data-label="位置">{{ $item['location'] }}</td></tr>@empty<tr><td class="primary empty" colspan="3">暂无道具</td></tr>@endforelse</tbody>
</table>
<x-sec title="资金修正" as="h2" />
<form method="post" action="{{ route('admin.user.update', $player) }}" class="form-grid indent">
    <x-op />
    <x-field label="增减金额" for="money_delta"><input class="input" id="money_delta" type="number" name="money_delta" min="-1000000000" max="1000000000" value="{{ old('money_delta') }}" required></x-field>
    <x-field label="原因" for="reason"><input class="input" id="reason" name="reason" maxlength="200" value="{{ old('reason') }}" required></x-field>
    <div class="actions"><button class="btn" type="submit">保存并记录审计</button></div>
</form>
<details class="danger-zone">
    <summary>删除账号</summary>
    <p>永久删除账号与角色。有效拍卖或出价必须先结算。</p>
    <form method="post" action="{{ route('admin.user.delete', $player) }}" class="form-grid indent">
        <x-op />
        <x-field label="您的密码" for="current_password"><input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required></x-field>
        <x-confirm word="DELETE" />
        <div class="actions"><button class="btn btn-danger" type="submit" data-confirm="确定永久删除这个账号和所有角色？">永久删除账号</button></div>
    </form>
</details>
@endsection
