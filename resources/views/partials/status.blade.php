<div class="status">@if($hud['setup'])现在让我们认识一下你吧：@elseif($hud['authenticated'])
<span class="status-team">{{ $hud['team'] }}</span><span class="status-item">资金 <x-money :amount="$hud['money']"/></span>
<span class="status-item">体力 <meter class="meter" min="0" max="{{ $hud['staminaMax'] }}" value="{{ $hud['stamina'] }}" low="20" high="50" optimum="100" aria-label="体力"></meter><span class="num">{{ $hud['stamina'] }}/{{ $hud['staminaMax'] }}</span></span>
<form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="btn-link">退出</button></form>
@else欢迎来到 [ 荣誉圣殿 ]@endif</div>
