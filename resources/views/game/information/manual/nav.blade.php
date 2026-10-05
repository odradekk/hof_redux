{{-- Switcher shared by the manual pages, in the legacy footer order (手册 - 教学 - 游戏数据). --}}
<h1 class="page-title">{{ \App\Http\Controllers\Game\InformationController::MANUAL[$section] }}</h1>
<nav aria-label="游戏说明"><ul class="tabs">
@foreach(\App\Http\Controllers\Game\InformationController::MANUAL as $key => $label)
<li><a href="{{ route('manual', $key === 'basic' ? null : $key) }}" @if($section === $key) aria-current="page" @endif>{{ $label }}</a></li>
@endforeach
<li><a href="{{ route('catalog') }}">游戏资料</a></li>
<li><a href="{{ route('updates') }}">更新公告</a></li>
</ul></nav>
