@extends('layouts.app')
@section('title', '游戏资料 · 行动条件')
@section('content')
@include('game.information.catalog.head')
<div class="doc">
<h2 class="sec">判定(judge)</h2>
<p class="indent prose">角色页“行动模式”中可以选择的全部判定。N 是判定右侧填写的数值：百分比判定填 0–100，人数、次数、等级判定填对应的数。行动时从第 1 行开始依次判定，第一条满足的判定执行对应技能；没有任何一条满足时，本次行动什么也不做。使用技能“{{ $data->text()->name('skills', 9000) }}”可以让相邻几行的判定同时满足才执行，见 <a href="{{ route('manual', 'advanced') }}#mj">高级指南</a>。</p>
<dl class="judge-list">
@foreach($groups as $group => $rows)
<dt>{{ $group }}</dt><dd><ul class="item-list">@foreach($rows as $row)<li data-id="conditions-{{ $row['id'] }}">{{ $row['text'] }}</li>@endforeach</ul></dd>
@endforeach
</dl>
<h2 class="sec">补充说明</h2>
<ul class="indent prose">
<li>“我方 HP N(%)以下”：任意一名存活的同伴生命比例在 N% 以下即满足。“平均”只计算存活的同伴；魔力判定不计算最大魔力为 0 的同伴。</li>
<li>蓄力指物理技能的准备中，咏唱指魔法技能的准备中。</li>
<li>人数类判定只计算存活者（“死者”判定除外）。召唤物计入人数。</li>
<li>“自己的行动回数 N 回以上”中，第一次行动时回数为 1。</li>
<li>“第 N 回 必定”：该行已被执行的次数少于 N 时满足，用于限制某个技能的使用次数。</li>
<li>“N% 的概率”：每次判定独立掷 1–100，结果不大于 N 时满足。</li>
<li>“敌方 Lv 超过 N 以上”：任意一名敌人（含已倒下者）等级不低于 N 时满足。</li>
</ul>
</div>
@endsection
