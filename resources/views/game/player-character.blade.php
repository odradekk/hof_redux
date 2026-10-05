@extends('layouts.app')
@section('title', $character->name)
@section('content')
@include('game.player-nav')
<h2>{{ $character->name }} · Lv.{{ $character->level }} {{ $job[$character->gender ? 'name_female' : 'name_male'] }}</h2>
<img src="{{ asset('image/char/'.$job[$character->gender ? 'img_female' : 'img_male']) }}" alt="">
<p>HP {{ $character->stats['hp'] }} / {{ $character->stats['maxhp'] }} · SP {{ $character->stats['sp'] }} / {{ $character->stats['maxsp'] }} · EXP {{ $character->xp }} / {{ App\Application\Player\PlayerRules::experienceRequired($character->level) ?? 'MAX' }}</p>
<h3>装备与被动技能合计</h3>
<table><thead><tr><th>属性</th><th>基础</th><th>加成</th><th>合计</th></tr></thead><tbody>
@foreach(['maxhp'=>'HP','maxsp'=>'SP','str'=>'STR','int'=>'INT','dex'=>'DEX','spd'=>'SPD','luk'=>'LUK'] as $stat => $label)
<tr><th>{{ $label }}</th><td>{{ $character->stats[$stat] }}</td><td>{{ $effective[$stat] - $character->stats[$stat] }}</td><td>{{ $effective[$stat] }}</td></tr>
@endforeach
</tbody></table>
<p>Atk / Matk: {{ implode(' / ', $effective['atk']) }} · Def / Mdef: {{ implode(' / ', $effective['def']) }}</p>
@if(!empty($effective['SPECIAL']['Summon']))
<p>召唤力 +{{ $effective['SPECIAL']['Summon'] }}%</p>
@endif
@if(!empty($effective['SPECIAL']['Pierce'][0]) || !empty($effective['SPECIAL']['Pierce'][1]))
<p>无视防御伤害（物理 / 魔法）：{{ implode(' / ', $effective['SPECIAL']['Pierce']) }}</p>
@endif
<h3>角色属性</h3>
<p>剩余属性点：{{ $character->stat_points }}</p>
<form method="post" action="{{ route('player.command', 'stats') }}">
@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}">
@foreach(App\Application\Player\PlayerRules::STATS as $stat)<label>{{ strtoupper($stat) }} {{ $character->stats[$stat] }} + <input type="number" min="0" max="{{ min($character->stat_points, 255 - $character->stats[$stat]) }}" value="0" name="stats[{{ $stat }}]"></label>
@endforeach

<button>分配属性点</button></form>
<h3>配置与保护</h3>
<form method="post" action="{{ route('player.command', 'position') }}">
@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}">
<label>配置 <select name="position"><option value="front" @selected($character->position === 'front')>前卫</option><option value="back" @selected($character->position === 'back')>后卫</option></select></label>
<label>保护后卫 <select name="guard">@foreach(['always'=>'必定','never'=>'放弃','life25'=>'HP >25%','life50'=>'HP >50%','life75'=>'HP >75%','prob25'=>'概率25%','prob50'=>'概率50%','prob75'=>'概率75%'] as $guard => $label)<option value="{{ $guard }}" @selected(($character->guard_policy['mode'] ?? 'always') === $guard)>{{ $label }}</option>
@endforeach
</select></label><button>保存</button></form>
<h3>装备</h3><p>负重上限 {{ App\Application\Player\PlayerRules::capacity($character) }}</p>
@forelse($equipment as $entry)
<section><h4>{{ $entry['row']->slot }}</h4>@include('game.player-item', ['data'=>$entry['data'], 'noJs'=>true])
<form method="post" action="{{ route('player.command', 'unequip') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><input type="hidden" name="slot" value="{{ $entry['row']->slot }}"><button>解除</button></form></section>

@empty
<p>没有装备</p>
@endforelse

<form method="post" action="{{ route('player.command', 'unequip-all') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><button>装备全部解除</button></form>
<form method="post" action="{{ route('player.command', 'equip') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}">
<label>装备道具 <select name="inventory_id" required>@foreach($inventory as $entry)@if(in_array($entry['data']['type'], $job['equip'] ?? [], true) && App\Application\Player\PlayerRules::slot($entry['data']['type']))<option value="{{ $entry['row']->id }}">{{ $entry['data']['name'] }} · 负重{{ $entry['data']['handle'] ?? 0 }} · {{ $entry['data']['option'] ?? '' }}</option>
@endif

@endforeach
</select></label><button>装备</button></form>
<h3>技能学习</h3><p>剩余技能点：{{ $character->skill_points }}</p>
<p>已习得：@foreach($character->skills as $skill){{ $catalog->get('skills', $skill)['name'] }}{{ $loop->last ? '' : ' · ' }}
@endforeach
</p>
<form method="post" action="{{ route('player.command', 'learn') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}">
<label>技能 <select name="skill_id" required>@foreach($availableSkills as $id)@php($skill = $catalog->get('skills', $id))<option value="{{ $id }}">{{ $skill['name'] }} · {{ $skill['learn'] ?? 0 }} 点</option>
@endforeach
</select></label><button>学习</button></form>
<h3>转职</h3><p>转职后装备将全部返回背包。</p>
@if(count($jobs))<form method="post" action="{{ route('player.command', 'job') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><select name="job_id">@foreach($jobs as $id => $rule)<option value="{{ $id }}">{{ $catalog->get('jobs', $id)[$character->gender ? 'name_female' : 'name_male'] }}</option>
@endforeach
</select><button>转职</button></form>
@else
<p>尚未满足转职条件</p>
@endif

<h3>行动模式</h3><p>按顺序判断。最多 {{ $maxPatterns }} 行；条件全部不满足时跳过本次行动。</p>
<form method="post" action="{{ route('player.command', 'tactics') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}">
<table><thead><tr><th>行</th><th>条件</th><th>数值</th><th>行动</th></tr></thead><tbody>
@foreach($tactics as $i => $row)<tr><td>{{ $i + 1 }}</td><td><select aria-label="条件 {{ $i + 1 }}" name="tactics[{{ $i }}][judge]">@foreach($conditions as $id => $condition)<option value="{{ $id }}" @selected((string)$row['judge'] === (string)$id)>{{ $condition['exp'] }}</option>
@endforeach
</select></td><td><input aria-label="数值 {{ $i + 1 }}" type="number" min="0" max="9999" name="tactics[{{ $i }}][quantity]" value="{{ $row['quantity'] }}"></td><td><select aria-label="行动 {{ $i + 1 }}" name="tactics[{{ $i }}][action]">@foreach($character->skills as $id)@if(App\Application\Player\PlayerRules::isTacticAction((int) $id))<option value="{{ $id }}" @selected((string)$row['action'] === (string)$id)>{{ $catalog->get('skills', $id)['name'] }}</option>
@endif

@endforeach
</select></td></tr>
@endforeach

</tbody></table><button>保存行动模式</button></form>
@foreach(['tactics-insert'=>'插入行（最后一行被移除）','tactics-delete'=>'删除行（末尾补基础攻击）'] as $command => $label)<form method="post" action="{{ route('player.command', $command) }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><select aria-label="行" name="row">@for($i=0;$i<$maxPatterns;$i++)<option value="{{ $i }}">{{ $i+1 }}</option>
@endfor
</select><button>{{ $label }}</button></form>
@endforeach

<form method="post" action="{{ route('player.command', 'memo') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><button>与模式备忘交换</button></form>
<form method="post" action="{{ url('/characters/'.$character->id.'/simulation') }}">@include('game.player-token')<button>使用已保存模式进行镜像测试（10 次行动）</button></form>
<h3>改名</h3><p>消耗道具 7500。</p><form method="post" action="{{ route('player.command', 'rename') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><label>名字 <input name="name" maxlength="16" required></label><button>改名</button></form>
<h3>重置</h3><p>属性重置会归还点数并解除装备。技能重置保留初始技能，归还已学习技能的点数，并清除模式备忘。</p>
<form method="post" action="{{ route('player.command', 'reset') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><select aria-label="重置道具" name="item_id">@foreach([7510,7511,7512,7513,7520] as $id)<option value="{{ $id }}">{{ $catalog->get('items', $id)['name'] }}</option>
@endforeach
</select><button>消耗道具并重置</button></form>
<h3>离队</h3><p>解雇会永久移除此角色；装备返回背包。队伍必须保留一人。</p>
<form method="post" action="{{ route('player.command', 'dismiss') }}">@include('game.player-token')<input type="hidden" name="character_id" value="{{ $character->id }}"><button>解雇 {{ $character->name }}</button></form>
@endsection
