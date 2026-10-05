@extends('layouts.app')
@section('title', '游戏资料')
@section('content')
<x-sec as="h1" :title="'游戏资料 · '.$label" />
<x-tabs :items="$tabs" />
<form method="get" action="{{ route('catalog', $kind) }}" class="actions">
    <label for="catalog-query">搜索</label>
    <input class="input" id="catalog-query" name="q" value="{{ $query }}" maxlength="200">
    <button class="btn" type="submit">搜索</button>
</form>
<div class="catalog-grid">
    @forelse($records as $card)
        <article class="catalog-card" data-id="{{ $kind }}-{{ $card['id'] }}">
            <x-sec :title="$card['title']" as="h2" />
            <div class="catalog-images">
                @foreach($card['images'] as $image)
                    <figure><img src="{{ asset($image['path']) }}" alt="{{ $image['label'] }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}" loading="lazy"><figcaption>{{ $image['label'] }}</figcaption></figure>
                @endforeach
            </div>
            @if($card['description'] !== '')<p>{{ $card['description'] }}</p>@endif
            <dl>@foreach($card['facts'] as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl>
        </article>
    @empty
        <p class="empty">没有符合条件的资料</p>
    @endforelse
</div>
{{ $records->withQueryString()->links() }}
@endsection
