@extends('layouts.app')
@section('title','城镇')
@section('content')
<h2>城镇</h2><img src="{{ asset('image/other/town.gif') }}" alt="城镇">
<p><a href="{{ url('/shop') }}">商店</a> · <a href="{{ url('/shop') }}">打工</a> · <a href="{{ url('/characters') }}">招募</a> · <a href="{{ url('/crafting') }}">锻造</a> · <a href="{{ url('/crafting') }}">精炼</a> · <a href="{{ url('/auction') }}">拍卖</a> · <a href="{{ url('/ranking') }}">竞技场</a></p>
@foreach($announcements as $notice)<article><h3>{{ $notice->title }}</h3><p class="whitespace-pre-wrap">{{ $notice->body }}</p></article>@endforeach
<h3>广场留言板</h3><form method="post" action="{{ route('town.post') }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) Str::uuid() }}"><label>留言 <input name="body" maxlength="200" required></label><button>发送</button></form><p>最多保留 50 条留言，每条 1–200 个字符</p>
@foreach($messages as $message)<p><time>{{ $message->created_at }}</time> <strong>{{ $message->author_name }}</strong>: {{ $message->body }}</p>@endforeach
@endsection
