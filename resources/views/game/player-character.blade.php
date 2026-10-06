@extends('layouts.app')
@section('title', $character['name'])
@section('content')
<nav aria-label="切换角色"><ul class="inline-list">
    @foreach($characterLinks as $link)<li><a href="{{ $link['href'] }}" @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a></li>@endforeach
</ul></nav>
<ul class="inline-list inline-list-ruled" aria-label="本页分区">
    <li><a href="#c-status">人物状态</a></li>
    @if($character['stat_points'] > 0)<li><a href="#c-stat">角色属性</a></li>@endif
    <li><a href="#c-ai">行动模式</a></li><li><a href="#c-pos">位置 &amp; 保护</a></li>
    <li><a href="#c-equip">装备</a></li><li><a href="#c-skill">技能</a></li><li><a href="#c-other">其他</a></li>
</ul>

<x-sec title="人物状态" as="h1" id="c-status" :help="route('manual')" />
<div class="char-head">
    <x-carpet :unit="$unit" />
    <x-kv :rows="$statusRows" />
    <div class="specials meta">@foreach($specials as $special)<p>{{ $special }}</p>@endforeach</div>
</div>
<p class="hint indent">装备与被动技能合计：加成以绿色显示</p>

@if($character['stat_points'] > 0)
<form id="c-stat" method="post" action="{{ route('player.command', 'stats') }}">
    <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
    <x-sec title="角色属性" :help="route('manual')"><x-slot:aside>剩余属性点：<b class="charge">{{ $character['stat_points'] }}</b></x-slot:aside></x-sec>
    <div class="num-grid indent">
        @foreach($allocations as $stat)<label>{{ $stat['label'] }} <span class="meta">{{ $stat['value'] }}</span><input class="input" aria-label="分配{{ $stat['label'] }}" type="number" min="0" max="{{ $stat['max'] }}" value="{{ old('stats.'.$stat['id'], 0) }}" name="stats[{{ $stat['id'] }}]" required></label>@endforeach
    </div>
    <div class="actions indent"><button class="btn" type="submit">升值</button></div>
</form>
@endif

<form id="c-ai" method="post" action="{{ route('player.command', 'tactics') }}">
    <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
    <x-sec title="行动模式" :help="route('manual')"><x-slot:aside>最多 {{ $maxPatterns }} 行，自上而下判断</x-slot:aside></x-sec>
    <div class="indent">
        <table class="tbl tactics">
            <thead><tr><th scope="col">No</th><th scope="col">条件</th><th scope="col">数值</th><th scope="col">行动</th><th scope="col">选择</th></tr></thead>
            <tbody>
            @foreach($tactics as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><select class="select select-judge" aria-label="条件 {{ $i + 1 }}" name="tactics[{{ $i }}][judge]">
                        @foreach($conditions as $condition)<option value="{{ $condition['id'] }}" @if($condition['heading']) class="select0" disabled @else @selected((string) old('tactics.'.$i.'.judge', $row['judge']) === (string) $condition['id']) @endif>{{ $condition['label'] }}</option>@endforeach
                    </select></td>
                    <td><input class="input" aria-label="数值 {{ $i + 1 }}" type="number" min="0" max="9999" name="tactics[{{ $i }}][quantity]" value="{{ old('tactics.'.$i.'.quantity', $row['quantity']) }}" required></td>
                    <td><select class="select" aria-label="行动 {{ $i + 1 }}" name="tactics[{{ $i }}][action]">
                        @foreach($actions as $action)<option value="{{ $action['id'] }}" @selected((string) old('tactics.'.$i.'.action', $row['action']) === (string) $action['id'])>{{ $action['label'] }}</option>@endforeach
                    </select></td>
                    <td class="c"><input type="radio" name="row" value="{{ $i }}" aria-label="选择第 {{ $i + 1 }} 行" @checked((int) old('row', count($tactics) - 1) === $i)></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="actions">
            <button class="btn" type="submit">确定模式</button>
            <button class="btn" type="submit" name="submit" value="test">设置 &amp; 测试</button>
            <a href="{{ route('simulation') }}">模拟战斗</a>
        </div>
        <div class="actions">
            <button class="btn" type="submit" formaction="{{ route('player.command', 'memo') }}" formnovalidate>切换模式</button>
            <button class="btn" type="submit" formaction="{{ route('player.command', 'tactics-insert') }}" formnovalidate>添加</button>
            <button class="btn" type="submit" formaction="{{ route('player.command', 'tactics-delete') }}" formnovalidate>删除</button>
            <span class="hint">添加/删除作用于右侧选中的行</span>
        </div>
        <p class="hint">条件全部不满足时跳过本次行动。设置 &amp; 测试会保存模式并执行 10 次行动的镜像测试。</p>
    </div>
</form>

<form id="c-pos" method="post" action="{{ route('player.command', 'position') }}">
    <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
    <x-sec title="位置 & 保护" :help="route('manual')" />
    <div class="form-grid indent">
        <span class="label" id="position-label">位置</span><div class="choice-row" role="group" aria-labelledby="position-label">
            <label class="check"><input type="radio" name="position" value="front" @checked(old('position', $character['position']) === 'front')> 前卫</label>
            <label class="check"><input type="radio" name="position" value="back" @checked(old('position', $character['position']) === 'back')> 后卫</label>
        </div>
        <x-field label="护卫" for="guard"><select class="select" id="guard" name="guard">@foreach($guards as $guard)<option value="{{ $guard['id'] }}" @selected(old('guard', $character['guard_policy']['mode'] ?? 'always') === $guard['id'])>{{ $guard['label'] }}</option>@endforeach</select></x-field>
    </div>
    <div class="actions indent"><button class="btn" type="submit">设置</button></div>
</form>

<x-sec title="装备" id="c-equip" :help="route('manual')"><x-slot:aside>负重 <b class="charge num">{{ $weight }} / {{ $capacity }}</b></x-slot:aside></x-sec>
<div class="indent">
    <dl class="kv">@foreach($combatRows as $row)<dt class="{{ $row['tone'] }}">{{ $row['label'] }}</dt><dd class="{{ $row['tone'] }}">{{ $row['value'] }}</dd>@endforeach</dl>
    <form method="post" action="{{ route('player.command', 'unequip') }}">
        <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
        <table class="slots"><tbody>
        @foreach($slots as $slot)<tr><th scope="row">{{ $slot['label'] }}</th><td>
            @if($slot['line'])<label class="check"><input type="radio" name="slot" value="{{ $slot['id'] }}" aria-label="选择{{ $slot['label'] }}"><x-item :line="$slot['line']" /></label>
            @else<span class="meta">（空）</span>@endif
        </td></tr>@endforeach
        </tbody></table>
        <div class="actions"><button class="btn" type="submit">卸下</button><button class="btn" type="submit" formaction="{{ route('player.command', 'unequip-all') }}">全卸</button></div>
    </form>
    <details class="more"><summary>可装备道具（{{ count($inventory) }}）</summary>
        @if($inventory)
        <form method="post" action="{{ route('player.command', 'equip') }}">
            <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
            <ul class="item-list">@foreach($inventory as $entry)<li><label class="check"><input type="radio" name="inventory_id" value="{{ $entry['id'] }}" required><x-item :line="$entry['line']" /></label></li>@endforeach</ul>
            <div class="actions"><button class="btn" type="submit">装备</button></div>
        </form>
        @else<p class="empty">没有适合此职业的道具</p>@endif
    </details>
</div>

<x-sec title="技能" id="c-skill" :help="route('manual')"><x-slot:aside>剩余技能点：<b class="charge">{{ $character['skill_points'] }}</b></x-slot:aside></x-sec>
<div class="indent">
    <p class="bold u">掌握技能</p><ul class="item-list">@foreach($skills as $skill)<li><x-skill :line="$skill" /></li>@endforeach</ul>
    <p class="bold u">可学技能</p>
    @if($availableSkills)
    <form method="post" action="{{ route('player.command', 'learn') }}">
        <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
        <ul class="item-list">@foreach($availableSkills as $skill)<li><label class="check"><input type="radio" name="skill_id" value="{{ $skill['id'] }}" required @disabled(! $skill['affordable'])><x-skill :line="$skill['line']" />@unless($skill['affordable'])<span class="hint">（技能点不足）</span>@endunless</label></li>@endforeach</ul>
        <div class="actions"><button class="btn" type="submit">习得</button></div>
    </form>
    @else<p class="empty">目前没有可学习的新技能</p>@endif
    <p class="bold u">转职</p>
    @if($jobs)
    <form method="post" action="{{ route('player.command', 'job') }}">
        <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
        <x-unit-picker :units="$jobs" :selected="[]" name="job_id" type="radio" />
        <p class="hint">转职后装备将全部返回仓库</p>
        <div class="actions"><button class="btn" type="submit">转职</button></div>
    </form>
    @else<p class="empty">尚未满足转职条件</p>@endif
</div>

<x-sec title="其他" id="c-other" />
<details class="danger-zone indent"><summary>改名 / 重置 / 离队</summary>
    <x-sec title="改名" /><p>消耗改名道具。</p>
    <form method="post" action="{{ route('player.command', 'rename') }}">
        <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
        <div class="form-grid"><x-field label="新名字" for="character-name"><input class="input" id="character-name" name="name" value="{{ old('name') }}" maxlength="16" required></x-field></div>
        <div class="actions"><button class="btn" type="submit">改名</button></div>
    </form>
    <x-sec title="重置" /><p>属性重置会归还点数并解除装备。技能重置保留初始技能，归还已学习技能的点数，并清除模式备忘。</p>
    <form method="post" action="{{ route('player.command', 'reset') }}" data-confirm="确定消耗道具并重置角色吗？">
        <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
        <div class="form-grid"><x-field label="重置道具" for="reset-item"><select class="select" id="reset-item" name="item_id">@foreach($resetItems as $item)<option value="{{ $item['id'] }}">{{ $item['name'] }}</option>@endforeach</select></x-field></div>
        <div class="actions"><button class="btn btn-danger" type="submit">消耗道具并重置</button></div>
    </form>
    <x-sec title="离队" /><p>解雇会永久移除此角色；装备返回仓库。队伍必须保留一人。</p>
    <form method="post" action="{{ route('player.command', 'dismiss') }}" data-confirm="确定解雇此角色吗？此操作无法撤销。">
        <x-op /><input type="hidden" name="character_id" value="{{ $character['id'] }}">
        <div class="actions"><button class="btn btn-danger" type="submit">解雇 {{ $character['name'] }}</button></div>
    </form>
</details>
@endsection
