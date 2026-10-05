@extends('layouts.app')
@section('title', '更新公告')
@section('content')
<x-sec as="h1" title="更新公告" />
<ul class="feed">
    @forelse($announcements as $notice)
        <li><article><x-sec :title="$notice->title" as="h2" /><x-time :at="$notice->created_at" /><p class="whitespace-pre-wrap">{{ $notice->body }}</p></article></li>
    @empty
        <li class="empty">暂无公告</li>
    @endforelse
</ul>
{{ $announcements->links() }}
@endsection
