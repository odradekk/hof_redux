@extends('layouts.app')
@section('title', '管理控制台')
@section('content')
<x-sec as="h1" title="管理控制台" />
<x-kv :rows="$totals" />
<x-sec title="账号" as="h2" />
<table class="tbl tbl-stack">
    <thead><tr><th scope="col">账号</th><th scope="col">队伍</th><th scope="col">资金</th><th scope="col">权限</th></tr></thead>
    <tbody>@foreach($users as $player)<tr>
        <td class="primary"><a href="{{ route('admin.user', $player) }}">#{{ $player->id }} {{ $player->login }}</a></td>
        <td data-label="队伍">{{ $player->name }}</td>
        <td class="num" data-label="资金"><x-money :amount="$player->money" /></td>
        <td data-label="权限">{{ $player->is_admin ? '管理员' : '玩家' }}</td>
    </tr>@endforeach</tbody>
</table>
{{ $users->links() }}
<x-sec title="发布公告" as="h2" />
<form method="post" action="{{ route('admin.announcement') }}" class="form-grid indent">
    <x-op />
    <x-field label="标题" for="title"><input class="input" id="title" name="title" maxlength="120" value="{{ old('title') }}" required></x-field>
    <x-field label="内容" for="body"><textarea class="textarea" id="body" name="body" maxlength="20000" rows="5" required>{{ old('body') }}</textarea></x-field>
    <div class="actions"><button class="btn" type="submit">发布</button></div>
</form>
<x-sec title="内容审核" as="h2" />
<ul class="feed">
    @foreach($announcements as $notice)
        <li>
            <strong>{{ $notice->title }}</strong> <x-time :at="$notice->created_at" />
            <form method="post" action="{{ route('admin.moderate') }}" class="actions">
                <x-op /><input type="hidden" name="type" value="announcement"><input type="hidden" name="id" value="{{ $notice->id }}">
                <x-confirm word="DELETE" :id="'confirm-announcement-'.$notice->id" />
                <button class="btn btn-danger" type="submit" data-confirm="确定删除这条公告？">删除公告</button>
            </form>
        </li>
    @endforeach
    @foreach($messages as $message)
        <li>
            <strong>{{ $message->author_name }}</strong>：{{ $message->body }} <x-time :at="$message->created_at" />
            <form method="post" action="{{ route('admin.moderate') }}" class="actions">
                <x-op /><input type="hidden" name="type" value="message"><input type="hidden" name="id" value="{{ $message->id }}">
                <x-confirm word="DELETE" :id="'confirm-message-'.$message->id" />
                <button class="btn btn-danger" type="submit" data-confirm="确定删除这条留言？">删除留言</button>
            </form>
        </li>
    @endforeach
    @if($announcements->isEmpty() && $messages->isEmpty())<li class="empty">暂无待审核内容</li>@endif
</ul>
<x-sec title="战报清理" as="h2" />
<p class="hint">只删除战报内容，保留挑战记录、冷却和统计。不自动删除账号。</p>
<form method="post" action="{{ route('admin.reports') }}" class="form-grid indent">
    <x-op />
    <x-field label="记录类型" for="report-type"><select class="select" id="report-type" name="type"><option value="pve">普通</option><option value="boss">BOSS</option><option value="pvp">竞技场</option></select></x-field>
    <x-field label="早于日期" for="before"><input class="input" id="before" type="date" name="before" value="{{ old('before') }}" required></x-field>
    <x-confirm word="DELETE" id="confirm-reports" />
    <div class="actions"><button class="btn btn-danger" type="submit" data-confirm="确定清理所选日期之前的战报？">清理战报</button></div>
</form>
<x-sec title="运行维护" as="h2" />
<p class="hint">初始化缺少的首领、复活已到时间的首领、结算到期拍卖。不会强制重置有效拍卖。</p>
<form method="post" action="{{ route('admin.maintenance') }}" class="actions">
    <x-op /><x-confirm word="RUN" id="confirm-maintenance" /><button class="btn" type="submit">运行维护</button>
</form>
<x-sec title="管理审计" as="h2" />
<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th scope="col">时间</th><th scope="col">管理员</th><th scope="col">操作</th><th scope="col">对象</th><th scope="col">详情</th></tr></thead>
        <tbody>@forelse($audits as $audit)<tr>
            <td><x-time :at="$audit['at']" /></td><td>{{ $audit['admin'] }}</td><td>{{ $audit['action'] }}</td><td>{{ $audit['target'] }}</td>
            <td><details><summary>查看详情</summary><pre>{{ $audit['details'] }}</pre></details></td>
        </tr>@empty<tr><td colspan="5" class="empty">暂无审计记录</td></tr>@endforelse</tbody>
    </table>
</div>
@endsection
