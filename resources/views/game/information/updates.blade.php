@extends('layouts.app')
@section('title','更新公告')
@section('content')
<h2>更新公告</h2>@forelse($announcements as $notice)<article><h3>{{ $notice->title }}</h3><time>{{ $notice->created_at }}</time><p class="whitespace-pre-wrap">{{ $notice->body }}</p></article>@empty<p>暂无公告</p>@endforelse{{ $announcements->links() }}
@endsection
