@extends('layouts.app')
@section('title', '游戏设置')
@section('content')
@include('game.player-nav')
<h2>设置</h2>
<form method="post" action="{{ route('player.command', 'preferences') }}">@include('game.player-token')
<input type="hidden" name="record_battle_log" value="0"><label><input type="checkbox" name="record_battle_log" value="1" @checked($preferences['record_battle_log'] ?? true)>记录战斗</label>
<input type="hidden" name="no_js_inventory" value="0"><label><input type="checkbox" name="no_js_inventory" value="1" @checked($preferences['no_js_inventory'] ?? false)>展开所有道具详情（无需 JavaScript）</label>
<label>颜色（六位十六进制）<input name="color" value="{{ $preferences['color'] ?? 'ffffff' }}" pattern="[a-fA-F0-9]{6}" maxlength="6" required></label><button>保存</button></form>
<h2>队伍改名</h2><p>费用：100,000 Gold。队伍名称必须唯一。</p>
<form method="post" action="{{ route('player.command', 'team-name') }}">@include('game.player-token')<label>新名字 <input name="name" maxlength="16" required></label><button>支付 100,000 并改名</button></form>
@endsection
