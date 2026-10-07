<div class="status">@if($hud['setup'])现在让我们认识一下你吧：@elseif($hud['authenticated'])
<span class="status-team">{{ $hud['team'] }}</span>@if($hud['exploring'])<a class="status-item charge" href="{{ route('dungeon') }}">地下城探索中</a>@endif<span class="status-item">资金 <x-money :amount="$hud['money']"/></span>
<form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="btn-link">退出</button></form>
@else欢迎来到 [ 荣誉圣殿 ]@endif</div>
