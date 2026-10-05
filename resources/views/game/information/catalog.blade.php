@extends('layouts.app')
@section('title','游戏资料')
@section('content')
<h2>游戏资料 · {{ ['jobs'=>'职业','items'=>'道具','conditions'=>'行动条件','monsters'=>'怪物','skills'=>'技能','enchants'=>'附魔'][$kind] }}</h2><p>@foreach(['jobs'=>'职业','items'=>'道具','conditions'=>'条件','monsters'=>'怪物','skills'=>'技能','enchants'=>'附魔'] as $key=>$label)<a href="{{ route('catalog',$key) }}">{{ $label }}</a> · @endforeach</p>
<form><label>搜索 <input name="q" value="{{ $query }}" maxlength="100"></label><button>搜索</button></form>
<link rel="stylesheet" href="{{ asset('catalog.css') }}">
<div class="catalog-grid">
@foreach($records as $id=>$record)
@php($card = app(\App\Application\World\CatalogPresenter::class)->card($kind,$id,$record))
<article class="catalog-card"><h3>{{ $card['title'] }}</h3>
<div class="catalog-images">@foreach($card['images'] as $image)<figure><img src="{{ asset($image['path']) }}" alt="{{ $image['label'] }}"><figcaption>{{ $image['label'] }}</figcaption></figure>@endforeach</div>
@if($card['description'] !== '')<p>{{ $card['description'] }}</p>@endif
<dl>@foreach($card['facts'] as $label=>$value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl></article>
@endforeach</div>
{{ $records->withQueryString()->links() }}
@endsection
