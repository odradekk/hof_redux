@extends('layouts.app')
@section('title','战斗记录')
@section('content')
<h2>战斗记录</h2><p><a href="?type=pve">普通</a> · <a href="?type=boss">BOSS</a> · <a href="?type=pvp">竞技场</a></p>
@forelse($records as $record)<p><a href="{{ route(match($type) {'boss'=>'reports.boss','pvp'=>'reports.ranking',default=>'reports.show'},$record->id) }}">{{ $record->created_at }} · {{ $record->report['names'][0] ?? '队伍' }} vs {{ $record->report['names'][1] ?? '敌人' }}</a></p>@empty<p>暂无记录</p>@endforelse
{{ $records->withQueryString()->links() }}
@endsection
