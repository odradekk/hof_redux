@extends('layouts.app')
@section('title','账号管理')
@section('content')
<h2>#{{ $player->id }} · {{ $player->login }} · {{ $player->name }}</h2><p>Gold {{ $player->money }} · Created {{ $player->created_at }} · Last login {{ $player->last_login_at }}</p>
<h3>角色</h3>@foreach($player->characters as $character)<p>#{{ $character->id }} {{ $character->name }} · Lv {{ $character->level }} · {{ $character->job_id }} · {{ json_encode($character->stats) }}</p>@endforeach
<h3>道具</h3>@foreach($player->inventory as $item)<p>#{{ $item->id }} {{ $item->item_id }} × {{ $item->quantity }} · {{ $item->location }} · +{{ $item->refinement }}</p>@endforeach
<h3>资金修正</h3><form method="post" action="{{ route('admin.user.update',$player) }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) Str::uuid() }}"><label>增减金额 <input type="number" name="money_delta" min="-1000000000" max="1000000000" required></label><label>原因 <input name="reason" maxlength="200" required></label><button>保存并记录审计</button></form>
<h3>删除账号</h3><p>永久删除账号与角色。有效拍卖或出价必须先结算。</p><form method="post" action="{{ route('admin.user.delete',$player) }}">@csrf<label>您的密码 <input type="password" name="current_password" autocomplete="current-password" required></label><label>输入 DELETE <input name="confirm" pattern="DELETE" required></label><button>永久删除账号</button></form>
@endsection
