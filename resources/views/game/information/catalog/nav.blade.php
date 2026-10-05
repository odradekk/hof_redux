{{-- Legacy "| 职业(Job) | 道具(item) | 判定 |" switcher, extended to every published kind. --}}
<h1 class="page-title">游戏资料 <span class="meta">GameData · 内容版本 {{ substr($data->version(), 0, 12) }}</span></h1>
<nav aria-label="游戏资料分类"><ul class="tabs">
<li><a href="{{ route('catalog') }}" @if($kind === null) aria-current="page" @endif>总览</a></li>
@foreach(\App\Application\World\GameData::KINDS as $key => $label)
<li><a href="{{ route('catalog', $key) }}" @if($kind === $key) aria-current="page" @endif>{{ $label }}</a></li>
@endforeach
</ul></nav>
