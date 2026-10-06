@extends('layouts.app')
@section('title', '设置')
@section('content')
<x-sec title="显示设置" as="h1" />
<form method="post" action="{{ route('player.command', 'preferences') }}">
    <x-op />
    <div class="indent">
        <input type="hidden" name="record_battle_log" value="0"><p><label class="check"><input type="checkbox" name="record_battle_log" value="1" @checked(old('record_battle_log', $preferences['record_battle_log'] ?? true))> 记录战斗</label></p>
        <input type="hidden" name="no_js_inventory" value="0"><p><label class="check"><input type="checkbox" name="no_js_inventory" value="1" @checked(old('no_js_inventory', $preferences['no_js_inventory']))> 展开全部道具详情</label></p>
        <fieldset><legend>留言颜色</legend>
            <label class="check"><input type="radio" name="color" value="" @checked(old('color', $preferences['color']) === '')> 默认文字颜色</label>
            <div class="color-grid">
            @foreach($colors as $color)<label class="color-choice" title="#{{ $color }}"><input type="radio" name="color" value="{{ $color }}" aria-label="颜色 #{{ $color }}" @checked(old('color', $preferences['color']) === $color)><span class="color-swatch swatch-{{ $color }}" aria-hidden="true"></span></label>@endforeach
            </div>
        </fieldset>
        <div class="actions"><button class="btn" type="submit">保存显示设置</button></div>
    </div>
</form>

<x-sec title="队伍改名" />
<p class="indent">当前队伍：{{ $teamName }}。改名费用 <x-money :amount="100000" />，队伍名称必须唯一。</p>
<form method="post" action="{{ route('player.command', 'team-name') }}" data-confirm="确定支付 $ 100,000 修改队伍名称吗？">
    <x-op />
    <div class="form-grid indent"><x-field label="新名字" for="team-name"><input class="input" id="team-name" name="name" value="{{ old('name') }}" maxlength="16" required></x-field></div>
    <div class="actions indent"><button class="btn" type="submit">支付并改名</button></div>
</form>

<x-sec title="修改密码" />
<form method="post" action="{{ route('account.password') }}">
    <x-op />
    <div class="form-grid indent">
        <x-field label="当前密码" for="current_password"><input class="input" id="current_password" type="password" name="current_password" required autocomplete="current-password"></x-field>
        <x-field label="新密码" for="password" hint="密码长度为 12–72 个字符"><input class="input" id="password" type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"></x-field>
        <x-field label="确认新密码" for="password_confirmation"><input class="input" id="password_confirmation" type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></x-field>
    </div>
    <div class="actions indent"><button class="btn" type="submit">保存密码</button></div>
</form>

<x-sec title="删除账号" />
<details class="danger-zone indent" @if($errors->getBag('deleteAccount')->any()) open @endif><summary>永久删除账号</summary>
    <p>删除账号会永久移除队伍、全部角色和道具，无法恢复。</p>
    <form method="post" action="{{ route('account.delete') }}" data-error-bag="deleteAccount" data-confirm="确定永久删除账号、角色和道具吗？此操作无法撤销。">
        <x-op />
        <div class="form-grid">
            <x-field label="当前密码" for="delete-password" error-bag="deleteAccount"><input class="input" id="delete-password" type="password" name="current_password" required autocomplete="current-password"></x-field>
            <x-field label="输入 DELETE 确认" for="delete-confirm" error-bag="deleteAccount"><input class="input input-md" id="delete-confirm" name="confirm" required pattern="DELETE" autocomplete="off"></x-field>
        </div>
        <div class="actions"><button class="btn btn-danger" type="submit">永久删除账号</button></div>
    </form>
</details>
@endsection
