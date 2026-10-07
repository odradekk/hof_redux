@extends('layouts.app')
@section('title', '教学')
@section('content')
@include('game.information.manual.head')
<div class="doc">
@php($c = $constants)
{{-- Content and order follow legacy data/data.tutorial.php. --}}
<h2 class="sec">教程</h2>
<div class="indent">
<p>战斗基本攻略（下图）</p>
<figure><img src="{{ asset('image/manual/t001.gif') }}" width="398" height="183" alt="想象图：敌人攻击前卫；前卫保护后卫；后卫的法师强力攻击，牧师为前卫回复"><figcaption>想象图（原版图片）：左侧为敌方，右侧为我方。前卫（战士）守护，后卫的法师进行强力攻击，牧师为前卫回复。</figcaption></figure>
<p>基本上来说，应该把<b>战士</b>(Warrior)类适合进攻、能抗打的人物放在<b>前卫(Front)</b>位置上，<br>
使用<b>魔法</b>(Sorcerer)、抗击打能力弱的人物放在<b>后卫(Back)</b>位置上。</p>
<p>前卫保护后卫，攻击力强的后卫又可以打到敌人，<br>
后卫也可以帮受伤的前卫疗伤。这样的布局是最好的。</p>
</div>

<h2 class="sec">暂且这样试试吧！</h2>
<div class="indent">
<p>战士(Warrior)、巫师(Sorcerer) 以及牧师(Priest)<br>
都可以从菜单的<b>城镇(Town)</b> → <a href="{{ route('player.roster') }}">招募</a>（人才介绍所）中雇佣。<br>
雇佣了以后，就可以在角色页对其进行战斗上的设定。</p>
<div class="carpets">
<x-carpet :unit="['name' => '守护的人', 'label' => '前卫 · 战士', 'img' => 'image/char_rev/mon_079.gif']" />
<x-carpet :unit="['name' => '强力攻击的人', 'label' => '后卫 · 巫师', 'img' => 'image/char_rev/mon_018.gif']" />
<x-carpet :unit="['name' => '治疗的人', 'label' => '后卫 · 牧师', 'img' => 'image/char_rev/mon_214.gif']" />
</div>
<p>那么，赶快进入战斗吧。<br>
菜单中的冒险 → <a href="{{ route('dungeons.prepare', 'goblin_trail') }}">哥布林小径</a><br>
用雇佣来的同伴试试看，<br>
Battle!</p>
<p>战斗结束后，会显示战斗结果（战报）。<br>
在地下城中每次移动消耗 {{ $c['dungeon_move'] }} 体力，每场战斗再消耗 {{ $c['dungeon_battle'] }} 体力，受到的伤害不会自动恢复。角色 HP 归零会永久死亡，打不过就及时撤离。想先试试阵容的话，可以用不消耗体力、也没有奖励的<a href="{{ route('simulation') }}">模拟战</a>。</p>
</div>

<h2 class="sec">菜单的构成</h2>
@include('game.information.manual.menu-map')

<h2 class="sec" id="first">等等</h2>
<div class="indent">
<p class="u"><b>初期能雇佣的人物</b></p>
<table class="tbl tbl-stack">
<thead><tr><th>职业</th><th>特点</th><th>雇佣费用</th></tr></thead>
<tbody>
@php($traits = ['100' => '抗打。', '200' => '攻击力强，但是不抗打。', '300' => '治愈系。', '400' => '可以无视对手的前卫，直接攻击后卫。'])
@foreach($rules->recruits() as $recruit)
<tr><td class="primary"><a href="{{ route('catalog.entry', ['jobs', $recruit['job']]) }}">{{ $recruit['name'] }}</a>@if($recruit['name_female'] !== $recruit['name']) / {{ $recruit['name_female'] }}@endif</td><td data-label="特点">{{ $traits[$recruit['job']] ?? '' }}</td><td class="num" data-label="费用">{{ \App\Application\World\GameText::money($recruit['price']) }}</td></tr>
@endforeach
</tbody></table>
<p>注册时可以从战士和巫师中选择第一位同伴。队伍最多 {{ $c['party_max'] }} 人，每名角色初始体力为满值（{{ $c['stamina_base'] }} + 体质）。</p>
<p>更多说明请看 <a href="{{ route('manual') }}">手册</a>；各职业、道具、怪物的详细数据请看 <a href="{{ route('catalog') }}">游戏资料</a>。</p>
</div>
@endsection
