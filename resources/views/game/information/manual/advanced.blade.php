@extends('layouts.app')
@section('title', '高级指南')
@section('content')
@include('game.information.manual.head')
<div class="doc">
@php($c = $constants)
@php($text = $data->text())
@php($think = $data->skillLine(9000))
{{-- The first four sections and their anchors follow legacy data/data.manual1.php. --}}
<h2 class="sec" id="content">目录</h2>
<ul class="toc">
<li><a href="#mj">关于行动的多重判定</a></li>
<li><a href="#twenty">约 20% 的概率</a></li>
<li><a href="#def">关于防御力的数值</a></li>
<li><a href="#res">弱点属性以及无状态异常的原因</a></li>
<li><a href="#order">行动顺序、准备与僵直</a></li>
<li><a href="#damage">伤害与回复的计算</a></li>
<li><a href="#change">能力变化</a></li>
<li><a href="#summon">召唤物</a></li>
<li><a href="#circle">魔法阵</a></li>
<li><a href="#reward">经验与掉落</a></li>
</ul>

<h2 class="sec" id="mj">关于行动的多重判定 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>学会技能「{{ $think['name'] }}」的话，<br>就可以根据多个条件同时成立来决定行动。</p>
<div class="carpets"><x-carpet :unit="['name' => '战士（男）', 'img' => 'image/char/mon_079.gif']" /><x-carpet :unit="['name' => '战士（女）', 'img' => 'image/char/mon_080r.gif']" /></div>
<p>如果是战士系的话……</p>
<table class="tbl sample">
<thead><tr><th>No</th><th>判定</th><th>使用技能</th></tr></thead>
<tbody>
<tr><td>1</td><td>{{ $text->condition(1101, 50) }}</td><td><x-skill :line="$think" /></td></tr>
<tr><td>2</td><td>{{ $text->condition(1200, 20) }}</td><td><x-skill :line="$data->skillLine(3121)" /></td></tr>
<tr><td>3</td><td>{{ $text->condition(1902, 1) }}</td><td><x-skill :line="$data->skillLine(3110)" /></td></tr>
<tr><td>4</td><td>{{ $text->condition(1200, 30) }}</td><td><x-skill :line="$data->skillLine(1017)" /></td></tr>
<tr><td>5</td><td>{{ $text->condition(1000) }}</td><td><x-skill :line="$data->skillLine(1000)" /></td></tr>
</tbody></table>
<p>这种情况下，1 和 2 的</p>
<ul>
<li>{{ $text->condition(1101, 50) }}</li>
<li>{{ $text->condition(1200, 20) }}</li>
</ul>
<p>双方都满足的时候，才会使用「{{ $text->name('skills', 3121) }}」。</p>
<p>说明流程……</p>
<table class="tbl sample">
<thead><tr><th>No</th><th>判定</th><th>使用技能</th><th>流程</th></tr></thead>
<tbody>
<tr><td>1</td><td>判定 1</td><td>{{ $think['name'] }}</td><td class="charge">↓ 不满足时，跳到 3</td></tr>
<tr><td>2</td><td>判定 2</td><td>技能 1</td><td class="charge">← 1 + 2 都满足时，使用技能 1</td></tr>
<tr><td>3</td><td>判定 3</td><td>{{ $think['name'] }}</td><td class="charge">↓ 不满足时，跳到 6</td></tr>
<tr><td>4</td><td>判定 4</td><td>{{ $think['name'] }}</td><td class="charge">↓ 不满足时，跳到 6</td></tr>
<tr><td>5</td><td>判定 5</td><td>技能 2</td><td class="charge">← 3 + 4 + 5 都满足时，使用技能 2</td></tr>
<tr><td>6</td><td>判定 6</td><td>技能 3</td><td class="charge">← 6 满足时，使用技能 3</td></tr>
</tbody></table>
<p>一旦某一行不满足，同一组中后面的判定就不再检查（概率判定也不会掷骰），直接从下一组开始。<br>
“第 N 回 必定”的计数在整组被执行时，组内每一行都会加 1。</p>
</div>

<h2 class="sec" id="twenty">约 20% 的概率 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>把两个概率判定用多重判定组合起来，例如 70% 的概率 + 30% 的概率：</p>
<pre class="formula">0.7 × 0.3 = 0.21 = 21%</pre>
<p>想要单个判定做不到的概率时，可以这样组合。</p>
</div>

<h2 class="sec" id="def">关于防御力的数值 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>装备上显示的 <span class="recover">物理防御 a+b</span>、<span class="support">魔法防御 c+d</span>，<br>
前面是减伤的百分比，后面是直接扣去的值。</p>
<pre class="formula">受到的伤害 = 基础伤害 × (1 − a ÷ 100) − b</pre>
<p>但无论防御多高，伤害至少为基础伤害的 10%（向上取整）。“无视防御”的技能不经过这一步。</p>
</div>

<h2 class="sec" id="res">弱点属性以及无状态异常的原因 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>战斗中没有对应。<br>没有属性弱点；状态异常只有中毒一种。</p>
</div>

<h2 class="sec" id="order">行动顺序、准备与僵直 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>所有存活的角色和怪物同时积累“行动值”，每单位时间增加 <b>√速度 + 5</b>，先到 100 的行动；同时到达时随机决定。速度 100 的角色（15）比速度 25 的角色（10）大约多行动一半。</p>
<p>行动后行动值归零，再减去技能的<b>僵直</b>，所以僵直越大，下次行动越晚。<br>
<b>准备</b>大于 0 的技能，第一次选中时只开始蓄力 / 咏唱，行动值减去准备的量；等行动值再次到 100 时才发动。准备中倒下的话技能中断。蓄力 / 咏唱的开始不计入行动次数。</p>
<p>“延迟”效果会扣减目标的行动值；对共享首领只有三分之一的效果。“立即行动”等效果会直接推进目标的行动值。</p>
<p>每次轮到自己行动时（不是发动准备好的技能时），先处理每回合回复和中毒伤害，再检查行动模式。</p>
</div>

<h2 class="sec" id="damage">伤害与回复的计算 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<pre class="formula">基础伤害 = (√能力 × 10 + 攻击力) × 威力% × 技能倍率
伤害     = ceil(max(基础伤害 × 10%, 基础伤害 × (1 − 防御a ÷ 100) − 防御b + 无视防御伤害 × 威力%))
回复量   = ceil((√智慧 × 10 + 魔法攻击) × 威力%)</pre>
<ul class="prose">
<li>能力：物理技能用力量（标注“物理（敏捷）”的用敏捷），魔法技能用智慧。攻击力为对应的物理 / 魔法攻击。</li>
<li>技能倍率：例如「{{ $text->name('skills', 1022) }}」在后卫时为 4，「{{ $text->name('skills', 1200) }}」对中毒目标为 6。各技能的特殊效果见技能页。</li>
<li>无视防御伤害来自装备（如「{{ $text->name('items', 1020) }}」），按技能威力加到伤害上。</li>
<li>目标有屏障时，这次攻击伤害为 0，屏障消失。</li>
<li>中毒伤害：round(最大生命 × 10%) + ceil(等级 ÷ 2)，最多扣到剩 1。</li>
</ul>
</div>

<h2 class="sec" id="change">能力变化 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<ul class="indent prose">
<li>“力量 +N%”这类变化按<b>当前值</b>计算并四舍五入，可以叠加；“力量 +N”（不带 %）直接加上数值。</li>
<li>防御的提升按“剩余部分”计算：物理防御 40 时 +30% 变为 40 + (100 − 40) × 30% = 58。</li>
<li>力量、智慧、敏捷、速度的提升，最多到角色原本能力值的 25 倍。</li>
<li>共享首领受到的生命上限、魔力上限、攻击、防御的降低效果减半。</li>
<li>带威力的辅助技能（回复 + 强化），会把能力变化套用两次。这是原版的实际行为，予以保留。</li>
<li>战斗中的能力变化只在本场有效。</li>
</ul>

<h2 class="sec" id="summon">召唤物 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent">
<p>召唤技能召唤出的怪物会站在召唤者一方战斗。召唤物的生命、魔力、各能力值和攻击力按召唤者放大：</p>
<pre class="formula">倍率 = (1 + (√敏捷 × 5 + 幸运) ÷ 250) × (100 + 召唤力加成) ÷ 100</pre>
<ul class="prose">
<li>召唤力加成来自鞭、召唤之书等装备（显示为“召唤力 +N%”）。</li>
<li>标有“召唤物立即行动”的技能，召唤物出现后马上行动。</li>
<li>召唤物倒下后会消失，不能被复活；计入人数类判定。</li>
<li>召唤物被击倒不给经验和道具。</li>
</ul>
</div>

<h2 class="sec" id="circle">魔法阵 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<div class="indent prose">
<p>双方各有魔法阵数（0–5）。部分技能会增加我方的魔法阵、减少敌方的魔法阵，部分强力魔法需要消耗我方的魔法阵，不足时使用失败。行动条件中可以按双方的魔法阵数判定。</p>
</div>

<h2 class="sec" id="reward">经验与掉落 <span class="sec-aside"><a href="#content" aria-label="回到目录">↑</a></span></h2>
<ul class="indent prose">
<li>击倒怪物后，该怪物的经验由我方当时存活的角色平分（向上取整），金钱全额获得。</li>
<li>每只怪物按掉落表最多掉落 1 件道具（掉落率见 <a href="{{ route('catalog', 'monsters') }}">怪物</a>）。</li>
<li>被复活后再次击倒的怪物，只给一半经验，不再给金钱和道具。</li>
<li>共享首领按每次造成的伤害给经验；击倒的那一队再得到击倒奖励。</li>
<li>模拟战和竞技场没有任何奖励。</li>
</ul>
</div>
@endsection
