@extends('game.information.layout')
@section('title', '游戏资料 · 附魔')
@section('info')
@include('game.information.catalog.nav')
<h2 class="sec">附魔(Enchant)</h2>
<div class="indent prose">
<p>在锻冶屋“制作”装备时，成品会随机获得附加能力。每次制作掷一个 1–9 的数：</p>
<ul>
<li>1–3：获得 1 个<b>低级</b>附魔；</li>
<li>4–6：获得 1 个<b>高级</b>附魔；</li>
<li>7–9：高级、低级各 1 个。</li>
</ul>
<p>附魔从该装备类型的候选表中等概率抽取。制作时额外放入 1 个“特殊材料”，会再固定获得该材料的附魔。效果的套用顺序为：精炼 → 特殊材料 → 高级 → 低级；百分比附魔按套用时的数值计算并四舍五入。</p>
</div>
<h2 class="sec">特殊材料</h2>
<table class="tbl tbl-stack">
<thead><tr><th>材料</th><th>附加效果</th></tr></thead>
<tbody>@foreach($materials as $material)<tr><td class="primary">@include('game.information.item-line', ['line' => $material['item'], 'stats' => false])</td><td data-label="效果">@if($material['enchant']['name'] !== $material['enchant']['effect'])<b>{{ $material['enchant']['name'] }}</b> · @endif{{ $material['enchant']['effect'] }}</td></tr>@endforeach</tbody>
</table>
<h2 class="sec">各类型的候选表</h2>
<table class="tbl tbl-stack">
<thead><tr><th>类型</th><th>低级候选</th><th>高级候选</th></tr></thead>
<tbody>@foreach($pools as $pool)<tr><td class="primary">{{ $pool['type'] }}</td><td class="num" data-label="低级">{{ $pool['low'] }} 种</td><td class="num" data-label="高级">{{ $pool['high'] }} 种</td></tr>@endforeach</tbody>
</table>
<p class="meta">各候选的具体效果列在对应道具页的“附魔候选”中。</p>
<h2 class="sec">全部附魔效果</h2>
<table class="tbl tbl-stack">
<thead><tr><th>编号</th><th>效果</th></tr></thead>
<tbody>@foreach($rows as $row)<tr><td class="primary">{{ $row['id'] }}@if($row['name'] !== $row['effect']) · {{ $row['name'] }}@endif</td><td data-label="效果">{{ $row['effect'] }}</td></tr>@endforeach</tbody>
</table>
@endsection
