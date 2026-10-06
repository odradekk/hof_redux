@extends('layouts.app')
@section('title', '游戏资料 · 数值规则')
@section('content')
@include('game.information.catalog.head')
<div class="doc">
@php($c = $constants)
@php($money = fn ($amount) => \App\Application\World\GameText::money($amount))
<h2 class="sec">数值规则</h2>
<ul class="toc">
<li><a href="#growth">等级与经验</a></li><li><a href="#vitals">生命与魔力</a></li><li><a href="#patterns">行动模式行数</a></li>
<li><a href="#capacity">负重</a></li><li><a href="#battle">战斗计算</a></li><li><a href="#stamina">体力</a></li>
<li><a href="#economy">商店、雇佣与打工</a></li><li><a href="#refine">精炼</a></li><li><a href="#auction">拍卖</a></li>
<li><a href="#arena">竞技场</a></li><li><a href="#boss">共享首领</a></li><li><a href="#other">其他</a></li>
</ul>

<h2 class="sec" id="growth">等级与经验</h2>
<ul class="indent prose">
<li>最高等级 {{ $c['max_level'] }}；每项能力值最高 {{ $c['stat_cap'] }}。</li>
<li>每升 1 级获得 3 个属性点和 1 个技能点。</li>
<li>经验达到下表数值时升级，经验归零；一次获得的经验最多升 1 级，多出的部分不保留。</li>
<li>击倒怪物的经验由当时存活的角色平分（向上取整），倒下的角色得不到经验。</li>
</ul>
<ol class="exp-grid indent" aria-label="各等级升级所需经验">
@foreach($rules->experienceTable() as $row)<li><b class="meta">Lv.{{ $row['level'] }}</b><span class="num">{{ number_format($row['next']) }}</span></li>@endforeach
</ol>

<h2 class="sec" id="vitals">生命与魔力</h2>
<p class="indent">最大生命与最大魔力由职业系数、等级和能力值决定，加点、升级、转职和重置时重新计算：</p>
<pre class="formula">最大生命 = round(100 × 生命系数 × (1 + (等级 − 1) ÷ 49) × (1 + (255² − (255 − 力量)²) ÷ 255²))
最大魔力 = round(100 × 魔力系数 × (1 + (等级 − 1) ÷ 49) × (1 + (255² − (255 − 智慧)²) ÷ 255²))</pre>
<p class="indent">战斗时再加上装备与被动技能的加成：先乘以（1 + 百分比加成），再加固定加成。每场战斗开始时生命、魔力全满，战斗中的损伤不会保留。各职业系数见 <a href="{{ route('catalog', 'jobs') }}">职业</a>。</p>

<h2 class="sec" id="patterns">行动模式行数</h2>
<p class="indent">可设定的行动模式行数由智慧决定，等级 30 以上再加 1 行。</p>
<table class="tbl tbl-stack">
<thead><tr><th>智慧</th><th>行数</th><th>等级 30 以上</th></tr></thead>
<tbody>@foreach($rules->patternTable() as $row)<tr><td class="primary">{{ $row['from'] }}–{{ $row['to'] }}</td><td class="num" data-label="行数">{{ $row['rows'] }}</td><td class="num" data-label="Lv30+">{{ $row['rows30'] }}</td></tr>@endforeach</tbody>
</table>

<h2 class="sec" id="capacity">负重</h2>
<pre class="formula">负重上限 = 5 + floor(等级 ÷ 10) + floor(敏捷 ÷ 5)</pre>
<p class="indent">已装备道具的重量合计不能超过负重上限。装备双手武器时会卸下盾。</p>

<h2 class="sec" id="battle">战斗计算</h2>
<h3 class="bold u">行动顺序</h3>
<p class="indent">所有存活者同时积累行动值，速度越快积累越快（每单位时间 √速度 + 5），先到 100 的人行动，同时到达时随机。行动后行动值归零，技能的“僵直”再从中扣除；“准备”大于 0 的技能先进入蓄力 / 咏唱，行动值再积累“准备”的量后才发动（发动前倒下则中断）。共享首领受到的延迟效果只有三分之一。</p>
<h3 class="bold u">伤害</h3>
<pre class="formula">基础 = (√能力 × 10 + 攻击力) × 威力% × 倍率
       能力：物理技能用力量（标注“敏捷”的用敏捷），魔法用智慧；攻击力用对应的物理 / 魔法攻击
伤害 = ceil(max(基础 × 10%, 基础 × (1 − 防御a ÷ 100) − 防御b + 无视防御伤害 × 威力%))
回复 = ceil((√智慧 × 10 + 魔法攻击) × 威力%)</pre>
<p class="indent">“无视防御”的技能跳过防御一步。处于屏障状态的目标，下一次攻击的基础伤害变为 0，屏障消失；追加的无视防御伤害仍按公式计算。每次攻击至少造成经过屏障处理后的基础伤害的 10%。</p>
<h3 class="bold u">中毒</h3>
<p class="indent">中毒的角色每次轮到自己行动时受到 round(最大生命 × 10%) + ceil(等级 ÷ 2) 点伤害，不会因此倒下（最少剩 1）。共享首领改为 min(200, 当前生命的 1% × 50–150% 随机)。毒耐性按百分比降低中毒几率；没有毒耐性时必定中毒。</p>
<h3 class="bold u">后卫保护</h3>
<p class="indent">敌人对后卫使用单体或随机目标的攻击时，生命大于 1 的前卫按随机顺序检查自己的保护方式，第一个满足的人代替后卫承受。“概率”方式掷 1–100，小于 25 / 50 / 75 时保护；“生命”方式要求自己的生命比例高于 25% / 50% / 75%。标注“前卫无法保护”的技能和辅助技能不会被保护。</p>
<h3 class="bold u">结束与奖励</h3>
<ul class="indent prose">
<li>一方全员倒下时结束；行动次数达到 {{ $c['actions'] }} 次时也结束（模拟战 {{ $c['simulation_actions'] }} 次）。普通战斗判为平局；竞技场比较存活的角色人数（不含召唤物），多的一方获胜，相同为平局。</li>
<li>击倒怪物时，经验、金钱和掉落归对方队伍。复活后再次被击倒的怪物只给一半经验，不再给金钱和道具。</li>
<li>每只怪物最多掉落 1 件道具，各道具的掉落率见怪物页。</li>
<li>模拟战和竞技场不获得任何奖励，也不改变角色。</li>
</ul>

<h2 class="sec" id="stamina">体力</h2>
<dl class="kv indent">
<dt>上限</dt><dd>每名角色 {{ $c['stamina_max'] }}</dd>
<dt>恢复</dt><dd>在城镇中每天 {{ $c['stamina_day'] }}（约每 {{ round($c['stamina_seconds'], 1) }} 秒 1 点），离线时也会恢复；在地下城中不会自然恢复</dd>
<dt>HP / SP</dt><dd>战斗伤害会保留；在城镇中每小时恢复上限的 {{ $c['health_hour'] }}%，在地下城中不会自然恢复</dd>
<dt>疲劳</dt><dd>@foreach($c['fatigue'] as $minimum => $penalty)体力 ≥ {{ $minimum }}：力量、智力、灵巧、速度、幸运 -{{ $penalty }}%@if(! $loop->last)；@endif @endforeach</dd>
<dt>普通狩猎</dt><dd>每次 {{ $c['hunt'] }}</dd>
<dt>共享首领</dt><dd>每次 {{ $c['boss_stamina'] }}</dd>
<dt>打工</dt><dd>{{ $c['work_stamina'] }} 体力换 {{ $money($c['work_pay']) }}</dd>
<dt>模拟战、竞技场</dt><dd>不消耗</dd>
</dl>

<h2 class="sec" id="economy">商店、雇佣与打工</h2>
<ul class="indent prose">
<li>商店出售 {{ count($data->shop()) }} 种道具，价格为道具的买价；卖出价格为买价的 1/5（材料等另有设定的除外）。精炼过或带附魔的道具也按基础卖价收购。</li>
<li>队伍最多 {{ $c['party_max'] }} 名角色，每次出战选择 1–{{ $c['party_max'] }} 名。解雇角色时，其装备回到仓库。</li>
</ul>
<table class="tbl tbl-stack">
<thead><tr><th>雇佣</th><th>费用</th></tr></thead>
<tbody>@foreach($rules->recruits() as $recruit)<tr><td class="primary"><a href="{{ route('catalog.entry', ['jobs', $recruit['job']]) }}">{{ $recruit['name'] }}</a>@if($recruit['name_female'] !== $recruit['name']) / {{ $recruit['name_female'] }}@endif</td><td class="num" data-label="费用">{{ $money($recruit['price']) }}</td></tr>@endforeach</tbody>
</table>

<h2 class="sec" id="refine">精炼</h2>
<p class="indent">可精炼的类型：{{ implode('、', $c['refinable']) }}。每次尝试收取道具买价的一半，最高 +10。失败时道具损毁。一次最多连续尝试 10 次，资金不足时停止。</p>
<table class="tbl tbl-stack">
<thead><tr><th>精炼</th><th>成功率</th><th>攻击力</th><th>防御</th></tr></thead>
<tbody>@foreach($rules->refineTable() as $row)<tr><td class="primary">+{{ $row['from'] }} → +{{ $row['to'] }}</td><td class="num" data-label="成功率">{{ $row['chance'] }}%</td><td class="num" data-label="攻击力">+{{ $row['atk'] }}</td><td class="num" data-label="防御">+{{ $row['def'] }}</td></tr>@endforeach</tbody>
<caption>攻击力、防御为精炼到该等级后相对原值的提升，向上取整。</caption>
</table>
<p class="indent">制作的配方、费用与附魔规则见各道具页和 <a href="{{ route('catalog', 'enchants') }}">附魔</a>。</p>

<h2 class="sec" id="auction">拍卖</h2>
<dl class="kv indent">
<dt>会员卡</dt><dd>{{ $money($c['auction_card']) }}，持有后才能出品和出价</dd>
<dt>出品费</dt><dd>每次 {{ $money($c['auction_fee']) }}；同一人两次出品至少间隔 {{ $c['auction_interval'] }} 秒</dd>
<dt>出品时间</dt><dd>{{ implode(' / ', $c['auction_hours']) }} 小时</dd>
<dt>同时出品</dt><dd>全服最多 {{ $c['auction_max'] }} 件</dd>
<dt>最低出价</dt><dd>当前价 + max(100, 当前价 ÷ 10)</dd>
<dt>延长</dt><dd>结束前 {{ $c['auction_extend'] }} 分钟内有人出价时，结束时间延长到出价后 {{ $c['auction_extend'] }} 分钟</dd>
<dt>托管</dt><dd>出价金额立即扣除并托管；被超过时全额退还。结束时成交金额交给出品者，无人出价则道具退回。</dd>
<dt>可出品类型</dt><dd>{{ implode('、', $c['auction_types']) }}</dd>
</dl>

<h2 class="sec" id="arena">竞技场</h2>
<ul class="indent prose">
<li>登记 1–{{ $c['party_max'] }} 名角色作为竞技场队伍；更换登记阵容需间隔 {{ $c['rank_team_hours'] }} 小时。</li>
<li>名次分阶：@foreach($rules->rankTiers() as $tier => $positions)第 {{ $tier }} 阶 {{ implode('、', $positions) }} 位；@endforeach之后每 3 个名次一阶。</li>
<li>挑战时从上一阶中随机选出一名对手。获胜则互换名次，{{ $c['rank_win'] }} 秒后可以再次挑战；失败或平局需等待 {{ \App\Application\World\GameText::duration($c['rank_other']) }}。</li>
<li>首次挑战时排在最后一名之后；没有人登记时，第一个挑战者直接成为第 1 名。</li>
<li>第 1 名不能挑战，防守成功会记录在案。</li>
</ul>

<h2 class="sec" id="boss">共享首领</h2>
<ul class="indent prose">
<li>每次挑战消耗 {{ $c['boss_stamina'] }} 体力，两次挑战至少间隔 {{ $c['boss_cooldown'] }} 分钟；出战角色的等级合计不能超过该首领的限制。</li>
<li>首领的生命由所有玩家共同削减，生命与魔力不公开。首领身边会随机出现随从。</li>
<li>每次挑战按造成的伤害获得经验；击倒首领的队伍再获得击倒奖励与掉落。被击倒后经过复活时间重新出现。</li>
<li>各首领的等级限制、随从与复活时间见 <a href="{{ route('catalog', 'monsters') }}">怪物</a> 的“共享首领”一节。</li>
</ul>

<h2 class="sec" id="other">其他</h2>
<dl class="kv indent">
<dt>队伍改名</dt><dd>{{ $money($c['rename_team']) }}</dd>
<dt>角色改名</dt><dd>消耗道具 <a href="{{ route('catalog.entry', ['items', 7500]) }}">{{ $data->text()->name('items', 7500) }}</a></dd>
@foreach($rules->resetItems() as $reset)<dt><a href="{{ route('catalog.entry', ['items', $reset['id']]) }}">{{ $data->text()->name('items', $reset['id']) }}</a></dt><dd>重置{{ $reset['effect'] }}</dd>@endforeach
<dt>广场留言</dt><dd>保留最新 {{ $c['board'] }} 条，每条 1–200 字</dd>
</dl>
</div>
@endsection
