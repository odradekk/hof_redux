@extends('layouts.app')
@section('title','游戏资料')
@section('content')
<h2>游戏资料 · {{ $kind }}</h2><p>@foreach(['jobs'=>'职业','items'=>'道具','conditions'=>'条件','monsters'=>'怪物','skills'=>'技能','enchants'=>'附魔'] as $key=>$label)<a href="{{ route('catalog',$key) }}">{{ $label }}</a> · @endforeach</p>
<form><label>搜索 <input name="q" value="{{ $query }}" maxlength="100"></label><button>搜索</button></form>
@foreach($records as $id=>$record)<article class="card"><h3>{{ $id }} · {{ $record['name'] ?? $record['name_male'] ?? $record['UnionName'] ?? '' }}</h3>@if(isset($record['img']))<img src="{{ asset('image/'.($kind === 'items' ? 'icon/' : 'char/').basename($record['img'])) }}" alt="">@endif<dl>@foreach($record as $key=>$value)<dt>{{ $key }}</dt><dd>{{ is_array($value) ? json_encode($value,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($value) ? ($value ? 'true' : 'false') : $value) }}</dd>@endforeach</dl></article>@endforeach
{{ $records->withQueryString()->links() }}<p>Content version: {{ $version }}</p>
@endsection
