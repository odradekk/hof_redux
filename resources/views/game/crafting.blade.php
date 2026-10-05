@extends('layouts.app')
@section('title', '工房')
@section('content')
@include('game.player-nav')
<h2>制作工房</h2><img src="{{ asset('image/char/mon_053rz.gif') }}" alt=""><p>制作费用为零。消耗配方材料，随机生成附加能力。特殊材料可追加一个属性。</p>
<form method="post" action="{{ route('player.command', 'craft') }}">@include('game.player-token')
<label>配方 <select name="item_id">@foreach($recipes as $id => $recipe)<option value="{{ $id }}">{{ $catalog->get('items', $id)['name'] }} · @foreach($recipe['ingredients'] as $material => $quantity){{ $catalog->get('items', $material)['name'] }} ×{{ $quantity }}{{ $loop->last ? '' : ', ' }}
@endforeach
</option>
@endforeach
</select></label>
<label>追加材料 <select name="material"><option value="">不追加</option>@foreach($items as $entry)@if((int)$entry['row']->item_id >= 7000 && (int)$entry['row']->item_id < 7200 && !empty($entry['data']['Add']))<option value="{{ $entry['row']->item_id }}">{{ $entry['data']['name'] }} ×{{ $entry['row']->quantity }}</option>
@endif

@endforeach
</select></label><button>制作</button></form>
<h2>精炼工房</h2><img src="{{ asset('image/char/mon_053r.gif') }}" alt=""><p>每次费用：道具基础买价的一半（四舍五入）。失败会毁掉道具。资金不足时保留已完成的精炼。上限 +10。</p>
<p>+1–+4：100%；+5：60%；+6–+7：40%；+8–+9：20%；+10：10%。</p>
<form method="post" action="{{ route('player.command', 'refine') }}">@include('game.player-token')
<label>道具 <select name="inventory_id">@foreach($items as $entry)@if(in_array($entry['data']['type'], App\Application\Player\PlayerRules::REFINABLE, true) && $entry['row']->refinement < 10)<option value="{{ $entry['row']->id }}">{{ $entry['data']['name'] }} · {{ $entry['data']['option'] ?? '' }} · 每次 {{ number_format(round($entry['data']['buy']/2)) }}</option>
@endif

@endforeach
</select></label>
<label>回数 <input type="number" name="times" value="1" min="1" max="10"></label><button>精炼（失败损失道具）</button></form>
@if(session('player_result.attempts'))<h3>精炼结果</h3><ol>@foreach(session('player_result.attempts') as $attempt)<li>+{{ $attempt['from'] }} → +{{ $attempt['to'] }} {{ $attempt['success'] ? '成功' : '失败' }} · {{ $attempt['cost'] }} Gold</li>
@endforeach
</ol>
@endif

<h3>所持材料与道具</h3><table><thead><tr><th>道具</th><th>数量</th></tr></thead><tbody>@foreach($items as $entry)<tr><td>@include('game.player-item', ['data'=>$entry['data']])</td><td>{{ $entry['row']->quantity }}</td></tr>
@endforeach
</tbody></table>
@endsection
