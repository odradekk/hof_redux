@extends('layouts.app')
@section('title', '城镇')
@section('content')
<x-sec as="h1" title="街" />
<div class="town">
    <ul>
        @foreach($facilities as $facility)
            <li>
                @if($facility['href'])<a href="{{ $facility['href'] }}">{{ $facility['label'] }}</a>@else{{ $facility['label'] }}@endif
                @if($facility['children'])<ul>@foreach($facility['children'] as $child)<li><a href="{{ $child['href'] }}">{{ $child['label'] }}</a></li>@endforeach</ul>@endif
            </li>
        @endforeach
    </ul>
</div>
@foreach($announcements as $notice)
    <article class="panel"><x-sec :title="$notice->title" as="h2" /><p class="whitespace-pre-wrap">{{ $notice->body }}</p></article>
@endforeach
<x-sec title="广场" as="h2" />
<form method="post" action="{{ route('town.post') }}" class="bbs-form">
    <x-op />
    <input class="input" id="body" name="body" maxlength="200" value="{{ old('body') }}" aria-label="留言内容" placeholder="1–200字" required>
    <button class="btn" type="submit">发言</button>
</form>
<p class="meta">最多保留50条留言，每条1–200个字符</p>
<x-feed :entries="$messages" />
@endsection
